#include "Contract.h"
#include "GoldInventory.h"
#include "NativePolicy.h"

#include "PlayerConversationRoutingPolicy.h"

#include <cstdlib>
#include <cstdint>
#include <fstream>
#include <iostream>
#include <iterator>
#include <limits>
#include <stdexcept>
#include <string>
#include <string_view>
#include <utility>
#include <vector>

#define CHECK(condition) do { if (!(condition)) throw std::runtime_error("check failed: " #condition); } while (false)

namespace
{
	struct InventoryGoldTestForm
	{
		std::uint32_t formId;

		bool IsGold() const noexcept { return formId == 0x0000000F; }
	};

	void TestGoldAmountFromInventory()
	{
		const InventoryGoldTestForm vanillaGold{ 0x0000000F };
		const InventoryGoldTestForm ordinaryItem{ 0x01005678 };
		using Row = std::pair<const InventoryGoldTestForm*, std::pair<std::int32_t, int>>;
		const std::vector<Row> inventory{
			{ &ordinaryItem, { 91, 0 } }, { nullptr, { 23, 0 } }, { &vanillaGold, { 17, 0 } }
		};
		CHECK(dql::inventory::GoldAmountFromInventory(inventory) == 17);

		const std::vector<Row> empty;
		CHECK(dql::inventory::GoldAmountFromInventory(empty) == 0);
		const std::vector<Row> withoutGold{ { &ordinaryItem, { 91, 0 } }, { nullptr, { 23, 0 } } };
		CHECK(dql::inventory::GoldAmountFromInventory(withoutGold) == 0);

		const std::vector<Row> zeroGold{ { &vanillaGold, { 0, 0 } }, { &ordinaryItem, { 91, 0 } } };
		CHECK(dql::inventory::GoldAmountFromInventory(zeroGold) == 0);
		const std::vector<Row> nonpositive{ { &vanillaGold, { -8, 0 } }, { &ordinaryItem, { 91, 0 } } };
		CHECK(dql::inventory::GoldAmountFromInventory(nonpositive) == 0);

		const std::vector<Row> maximum{ { &vanillaGold, { (std::numeric_limits<std::int32_t>::max)(), 0 } } };
		CHECK(dql::inventory::GoldAmountFromInventory(maximum) ==
			(std::numeric_limits<std::int32_t>::max)());
	}

	void WriteJson(const char* path, const dql::contract::Json& response)
	{
		if (!path || !*path) return;
		std::ofstream output(path, std::ios::binary | std::ios::trunc);
		if (!output) throw std::runtime_error("could not create response fixture");
		output << response.dump(2) << '\n';
		if (!output) throw std::runtime_error("could not write response fixture");
	}

	void WriteText(const char* path, std::string_view value)
	{
		if (!path || !*path) return;
		std::ofstream output(path, std::ios::binary | std::ios::trunc);
		if (!output) throw std::runtime_error("could not create text fixture");
		output << value << '\n';
		if (!output) throw std::runtime_error("could not write text fixture");
	}

	std::string ReadText(const char* path)
	{
		std::ifstream input(path, std::ios::binary);
		if (!input) throw std::runtime_error("could not read production source");
		return { std::istreambuf_iterator<char>(input), std::istreambuf_iterator<char>() };
	}

	bool ContainsBetween(std::string_view source, std::string_view start, std::string_view end,
		std::string_view expected)
	{
		const auto first = source.find(start);
		if (first == std::string_view::npos) return false;
		const auto last = source.find(end, first + start.size());
		return last != std::string_view::npos && source.substr(first, last - first).find(expected) != std::string_view::npos;
	}

	void TestPreDispatchRefusalFixture()
	{
		dql::contract::Envelope request{
			.requestId = "123e4567-e89b-12d3-a456-426614174210",
			.operation = "correspondence.compose",
			.viewToken = "123e4567-e89b-12d3-a456-426614174211",
			.nativeGeneration = "17",
			.serverContext = "synthetic-context",
			.payload = {{ "body", "C2-02-PRIVATE-COMPOSITION-SENTINEL" }}
		};
		const auto response = dql::contract::MakePreDispatchError(request, "stale_context",
			"The Department authorization context is no longer current.");
		CHECK(response["ok"] == false);
		CHECK(response["requestId"] == request.requestId);
		CHECK(response["operation"] == request.operation);
		CHECK(response["context"]["viewToken"] == request.viewToken);
		CHECK(response["context"]["nativeGeneration"] == request.nativeGeneration);
		CHECK(response["error"]["dispatched"] == false);
		CHECK(response.dump().find("C2-02-PRIVATE-COMPOSITION-SENTINEL") == std::string::npos);
		WriteJson(std::getenv("DQL_REFUSAL_FIXTURE_PATH"), response);
	}

	void TestQueueFullWriteRefusalFixture()
	{
		dql::contract::Envelope request{
			.requestId = "123e4567-e89b-12d3-a456-426614174212",
			.operation = "pcv.arm",
			.viewToken = "123e4567-e89b-12d3-a456-426614174211",
			.nativeGeneration = "17",
			.serverContext = "synthetic-context",
			.payload = {{ "operationId", "123e4567-e89b-12d3-a456-426614174213" },
				{ "text", "C2-02-PRIVATE-ARM-SENTINEL" }}
		};
		const auto response = dql::contract::MakePreDispatchError(request, "busy",
			"The Department server request could not be queued.");
		CHECK(response["requestId"] == request.requestId);
		CHECK(response["operation"] == request.operation);
		CHECK(response["error"]["dispatched"] == false);
		CHECK(response.dump().find("C2-02-PRIVATE-ARM-SENTINEL") == std::string::npos);
		WriteJson(std::getenv("DQL_QUEUE_REFUSAL_FIXTURE_PATH"), response);
	}

	void TestProductionSourceWiring()
	{
		const auto sourcePath = std::getenv("DQL_PLUGIN_SOURCE_PATH");
		CHECK(sourcePath && *sourcePath);
		const auto source = ReadText(sourcePath);
		CHECK(source.find("GetGoldAmount") == std::string::npos);
		CHECK(ContainsBetween(source, "Json BuildInventorySnapshot()", "static bool HasExactProperties",
			"dql::inventory::GoldAmountFromInventory("));
		CHECK(ContainsBetween(source, "ComposeResult PrepareComposePayload(", "void LoadOpenKey()",
			"dql::inventory::GoldAmountFromInventory(player->GetInventory())"));
		CHECK(source.find("SKSEPluginInfo(.Version = \"0.1.18.0\"_v,") != std::string::npos);
		CHECK(source.find("LogEvent(\"startup\", \"version_0_1_0_18\")") != std::string::npos);
		CHECK(source.find("void RespondPreDispatchError(") != std::string::npos);
		CHECK(source.find("dql::contract::MakePreDispatchError(request, code, message)") != std::string::npos);
		CHECK(source.find("dql::contract::MakeError(request, code, message, dispatched)") != std::string::npos);
		CHECK(ContainsBetween(source, "if (!request.serverContext", "if (!serverAuthenticated_)",
			"RespondPreDispatchError("));
		CHECK(ContainsBetween(source, "if (!serverAuthenticated_)",
			"if (request.operation == \"correspondence.inventory.read\")", "RespondPreDispatchError("));
		CHECK(ContainsBetween(source, "if (request.operation == \"correspondence.compose\")",
			"auto prepared = PrepareComposePayload(request);", "RespondPreDispatchError("));
		CHECK(ContainsBetween(source, "if (!prepared.payload)", "const bool isPcvOperation",
			"RespondPreDispatchError("));
		CHECK(source.find("request.operation == \"pcv.arm\"") != std::string::npos);
		CHECK(source.find("request.operation == \"pcv.end\"") != std::string::npos);
		CHECK(ContainsBetween(source, "static bool IsServerWriteOperation(", "bool IsCurrentRequest(", "correspondence.resume"));
		CHECK(!ContainsBetween(source, "static bool IsServerWriteOperation(", "bool IsCurrentRequest(", "correspondence.pending.read"));
		const auto pendingStart = source.find("if (request.operation == \"correspondence.pending.read\")");
		const auto pendingEnd = source.find("if (request.operation == \"correspondence.resume\")", pendingStart);
		CHECK(pendingStart != std::string_view::npos && pendingEnd != std::string_view::npos);
		const auto pendingHandler = source.substr(pendingStart, pendingEnd - pendingStart);
		CHECK(pendingHandler.find("CanUseCorrespondence") != std::string_view::npos);
		CHECK(pendingHandler.find("RespondPreDispatchError(") != std::string_view::npos);
		CHECK(pendingHandler.find("SubmitServerRequest(request, request.payload)") != std::string_view::npos);
		CHECK(pendingHandler.find("BuildInventorySnapshot(") == std::string_view::npos);
		CHECK(pendingHandler.find("PrepareComposePayload(") == std::string_view::npos);
		const auto resumeStart = source.find("if (request.operation == \"correspondence.resume\")");
		const auto resumeEnd = source.find("if (request.operation == \"correspondence.compose\")", resumeStart);
		CHECK(resumeStart != std::string_view::npos && resumeEnd != std::string_view::npos);
		const auto resumeHandler = source.substr(resumeStart, resumeEnd - resumeStart);
		CHECK(resumeHandler.find("CanUseCorrespondence") != std::string_view::npos);
		CHECK(resumeHandler.find("RespondPreDispatchError(") != std::string_view::npos);
		CHECK(resumeHandler.find("SubmitServerRequest(request, request.payload)") != std::string_view::npos);
		CHECK(resumeHandler.find("PrepareComposePayload(") == std::string_view::npos);
		CHECK(ContainsBetween(source, "void RespondServerWriteState(", "void HandleConnectionOperation(",
			"request.payload[\"operationId\"]"));
		CHECK(ContainsBetween(source, "if (isPcvOperation &&", "if (request.operation == \"pcv.send\")",
			"RespondPreDispatchError("));
		CHECK(ContainsBetween(source, "bool SubmitServerRequest(", "void OnServerCompletion(",
			"if (IsServerWriteOperation(request.operation)) {\n\t\t\t\t\tRespondPreDispatchError("));
		CHECK(ContainsBetween(source, "if (!submitted.queued)", "return true;",
			"RespondPreDispatchError(request, submitted.errorCode == dql::http::ErrorCode::queueFull"));
		CHECK(ContainsBetween(source, "void OnServerCompletion(", "const auto parsed = dql::contract::ParseServerResponse",
			"RespondServerWriteState(request, result.outcome == dql::http::Outcome::rejected ? \"rejected\" : \"unknown\")"));
		CHECK(ContainsBetween(source, "void HandleConnectionOperation(", "void OnConnectionCompletion(",
			"transport_.HasInFlightWrites()"));
		const auto connectionHandlerStart = source.find("void HandleConnectionOperation(");
		const auto connectionHandlerEnd = source.find("void OnConnectionCompletion(", connectionHandlerStart);
		CHECK(connectionHandlerStart != std::string::npos && connectionHandlerEnd != std::string::npos);
		const auto connectionHandler = source.substr(connectionHandlerStart, connectionHandlerEnd - connectionHandlerStart);
		CHECK(connectionHandler.find("companionOperations_") == std::string_view::npos);
		CHECK(connectionHandler.find("ClearCompanionOperations") == std::string_view::npos);
		CHECK(connectionHandler.find("BeginPcvSend") == std::string_view::npos);
		CHECK(connectionHandler.find("DispatchStaticCall") == std::string_view::npos);
		CHECK(source.find("BuildApprovalUrl") == std::string_view::npos);
		CHECK(source.find("ShellExecuteW") == std::string_view::npos);
		const auto connectionCompletionStart = source.find("void OnConnectionCompletion(");
		const auto connectionCompletionEnd = source.find("bool SubmitServerRequest(", connectionCompletionStart);
		CHECK(connectionCompletionStart != std::string::npos && connectionCompletionEnd != std::string::npos);
		const auto connectionCompletion = source.substr(connectionCompletionStart,
			connectionCompletionEnd - connectionCompletionStart);
		CHECK(connectionCompletion.find("companionOperations_") == std::string_view::npos);
		CHECK(connectionCompletion.find("BeginPcvSend") == std::string_view::npos);
		CHECK(connectionCompletion.find("NativeDestinationMatches() && vm->impl->DispatchStaticCall") != std::string_view::npos);
		CHECK(connectionCompletion.find("dql::connection::MakeClaimEvent(context.claimId, *tokenHash)") != std::string_view::npos);
		CHECK(connectionCompletion.find("RE::BSFixedString(\"AIAgentFunctions\")") != std::string_view::npos);
		CHECK(connectionCompletion.find("RE::BSFixedString(\"logMessage\")") != std::string_view::npos);
		CHECK(connectionCompletion.find("RE::BSFixedString(event->type)") != std::string_view::npos);
		CHECK(connectionCompletion.find("{ { \"state\", \"pending\" } }") != std::string_view::npos);
		CHECK(connectionCompletion.find("approvalOpened") == std::string_view::npos);
		CHECK(connectionCompletion.find("approvalPath") == std::string_view::npos);
		CHECK(ContainsBetween(source, "if (request.operation == \"connection.begin\")",
			"if (!request.serverContext", "HandleConnectionOperation(request, dql::connection::Action::begin)"));
		CHECK(ContainsBetween(source, "if (request.operation == \"connection.status\")",
			"if (!request.serverContext", "HandleConnectionOperation(request, dql::connection::Action::status)"));

		CHECK(source.find("FoldActorName(") == std::string::npos);
		CHECK(source.find("dql::policy::FindManagedActorIndex(labels, name)") != std::string::npos);
		CHECK(source.find("managedRoster_.emplace(dql::policy::ManagedActorNameKey(name)") != std::string::npos);
		CHECK(source.find("dql::policy::ManagedActorNameKey(label)") != std::string::npos);
		CHECK(source.find("NamesMatchManagedActor(*name, operation.target->name)") != std::string::npos);
		CHECK(source.find("FreshActorNameMatch freshMatch(expected->second, mapped)") != std::string::npos);
		CHECK(source.find("freshMatch.Observe(actor, *name)") != std::string::npos);
		CHECK(source.find("!freshMatch.IsUniqueMappedActor()") != std::string::npos);
		CHECK(source.find("std::optional<std::string> matchedName;") != std::string::npos);
		CHECK(source.find("matchedName = *name;") != std::string::npos);
		CHECK(source.find("target = { dql::companion::FormIdHex(matchedFormId), std::move(*matchedName) };") != std::string::npos);

		CHECK(source.find("initializeState_.ObserveDomReady(view_, view, creatingView_)") != std::string::npos);
		CHECK(source.find("creatingView_ = true;\n\t\t\tconst auto createdView = prisma_->CreateView") != std::string::npos);
		CHECK(source.find("initializeState_.TryMarkSent(view_, viewReadyToInitialize_, !viewToken_.empty())") != std::string::npos);
		CHECK(source.find("dql::policy::BuildInitializeInvokeScript(payload)") != std::string::npos);
		CHECK(source.find("prisma_->Invoke(view_, script.c_str(), &InitializeResultTrampoline)") != std::string::npos);
		CHECK(source.find("token != viewToken_ || generation != std::to_string(generation_)") != std::string::npos);
		CHECK(source.find("kMaxInitializeCallbackBytes + 1") != std::string::npos);
		CHECK(source.find("initializeState_.IsCurrentDomReady(view_)") != std::string::npos);
		CHECK(source.find("initializeState_.ResetSent()") != std::string::npos);
		CHECK(source.find("initializeState_.Reset()") != std::string::npos);
		CHECK(source.find("initializeSent_ = true") == std::string::npos);
	}

	void TestManagedNameNormalization()
	{
		const std::vector<std::string_view> names{
			"\t\xC3\x89LODIE \xC3\x81vila\r\n", "Lydia"
		};
		const auto index = dql::policy::FindManagedActorIndex(names, "\xC3\x89lodie \xC3\x81vila");
		CHECK(index && *index == 0);
		CHECK(dql::policy::ManagedActorNameKey(names[*index]) == "\xC3\x89lodie \xC3\x81vila");
		const std::vector<std::string_view> ambiguous{ "Lydia", " \tLYDIA\r\n" };
		CHECK(!dql::policy::FindManagedActorIndex(ambiguous, "Lydia"));
	}

	void TestInitializeLifecycle()
	{
		dql::policy::ViewInitializeState<std::uint64_t> state;
		state.Reset();
		state.ObserveDomReady(0, 2, true); // synchronous CreateView callback before assigning the returned handle
		CHECK(!state.IsCurrentDomReady(0));
		CHECK(state.IsCurrentDomReady(2)); // once CreateView returns, handle 2 is assigned
		CHECK(!state.TryMarkSent(2, false, true));
		CHECK(state.TryMarkSent(2, true, true)); // DOM-ready initialization must not wait for queued focus.
		CHECK(!state.TryMarkSent(2, true, true));
		state.ResetSent(); // close and reopen the same DOM-ready view
		CHECK(!state.TryMarkSent(2, true, false));
		CHECK(state.TryMarkSent(2, true, true));

		state.Reset(); // destroy and recreate; wait for the new view's callback
		CHECK(!state.TryMarkSent(2, true, true)); // reopen before DOM-ready is delivered
		state.ObserveDomReady(2, 1, false); // delayed callback from the destroyed old view
		CHECK(!state.IsCurrentDomReady(2));
		state.ObserveDomReady(2, 99, false); // unrelated view callback
		CHECK(!state.IsCurrentDomReady(2));
		state.ObserveDomReady(2, 2, false);
		CHECK(state.IsCurrentDomReady(2));
		CHECK(state.TryMarkSent(2, true, true));
		CHECK(!state.TryMarkSent(2, true, true));
	}

	void TestInitializeInvokeScript()
	{
		constexpr auto viewToken = "123e4567-e89b-12d3-a456-426614174211";
		constexpr auto generation = "42";
		const auto payload = dql::contract::Json{
			{ "version", 1 }, { "viewToken", viewToken },
			{ "nativeGeneration", generation }, { "serverContext", nullptr }
		}.dump();
		const auto script = dql::policy::BuildInitializeInvokeScript(payload);
		CHECK(script.find("window.departmentInitialize") != std::string::npos);
		CHECK(script.find("===true") != std::string::npos);
		CHECK(script.find("handler_missing") != std::string::npos);
		CHECK(script.find("status='exception'") != std::string::npos);
		CHECK(script.find("viewToken:payload.viewToken,nativeGeneration:payload.nativeGeneration") != std::string::npos);
		WriteText(std::getenv("DQL_INITIALIZE_SCRIPT_PATH"), script);
	}

	void TestRawStockRouteCanFallBackToSameName()
	{
		PlayerConversationRoutingPolicy::Request request;
		request.utterance = "inputtext";
		request.explicitTargetFormId = 0x01001234;
		request.explicitTargetName = "Lydia";
		request.directAddressRadius = 2048.0f;

		PlayerConversationRoutingPolicy::Candidate sameName;
		sameName.formId = 0x01005678;
		sameName.name = "Lydia";
		sameName.distance = 24.0f;
		sameName.facingDot = 1.0f;
		sameName.hardEligible = true;
		sameName.directEligible = true;

		const auto result = PlayerConversationRoutingPolicy::Select(request, { sameName });
		CHECK(request.explicitTargetFormId != sameName.formId);
		CHECK(result.kind == PlayerConversationRoutingPolicy::SelectionKind::Candidate);
		CHECK(result.candidateIndex == 0);
		CHECK(result.reason == "explicit_ui_target");
	}
}

int main()
{
	try {
		TestPreDispatchRefusalFixture();
		TestQueueFullWriteRefusalFixture();
		TestGoldAmountFromInventory();
		TestProductionSourceWiring();
		TestManagedNameNormalization();
		TestInitializeLifecycle();
		TestInitializeInvokeScript();
		TestRawStockRouteCanFallBackToSameName();
		std::cout << "Native boundary tests passed\n";
		return 0;
	} catch (const std::exception& error) {
		std::cerr << error.what() << '\n';
		return 1;
	}
}
