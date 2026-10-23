#include "../src/Contract.h"
#include "../src/NativePolicy.h"

#include <stdexcept>
#include <iostream>
#include <array>
#include <string>

using dql::contract::Json;

#define CHECK(condition) do { if (!(condition)) throw std::runtime_error("check failed: " #condition); } while (false)

namespace
{
	const std::string kToken = "123e4567-e89b-12d3-a456-426614174000";

	std::string MakeRequest(std::string operation = "bootstrap", std::string serverContext = "null", std::string payload = "{}")
	{
		return "{\"version\":1,\"requestId\":\"" + kToken + "\",\"operation\":\"" + operation +
			"\",\"context\":{\"viewToken\":\"" + kToken + "\",\"nativeGeneration\":\"42\",\"serverContext\":" +
			serverContext + "},\"payload\":" + payload + "}";
	}

	Json MakeCatalogData(std::size_t count = 1, std::string label = "Lydia")
	{
		Json actors = Json::array();
		for (std::size_t i = 0; i < count; ++i) {
			actors.push_back(Json{
				{ "actorKey", "npc:" + std::to_string(i + 1) }, { "label", label },
				{ "canWrite", true }, { "canReceive", true }, { "canSignAs", true }
			});
		}
		return Json{
			{ "actors", std::move(actors) }, { "limited", false }, { "revision", std::string(64, 'a') },
			{ "nextCursor", nullptr }
		};
	}
}

int main(int argc, char** argv)
{
	try {
	if (argc == 2 && std::string_view(argv[1]) == "--intentional-failure") {
		CHECK(false);
	}
	auto good = dql::contract::ParseRequest(MakeRequest());
	CHECK(good.envelope.has_value());
	CHECK(good.envelope->operation == "bootstrap");
	CHECK(!good.envelope->serverContext.has_value());
	CHECK(dql::contract::ParseRequest(MakeRequest("connection.begin", "null")).envelope.has_value());
	CHECK(dql::contract::ParseRequest(MakeRequest("connection.status", "null")).envelope.has_value());
	CHECK(!dql::contract::ParseRequest(MakeRequest("connection.begin", "\"scope-1\"")).envelope.has_value());
	CHECK(!dql::contract::ParseRequest(MakeRequest("connection.status", "null", "{\"serverBase\":\"http://x\"}")).envelope.has_value());

	auto normal = dql::contract::ParseRequest(MakeRequest("view.close", "\"scope-1\""));
	CHECK(normal.envelope.has_value());
	CHECK(normal.envelope->serverContext == "scope-1");
	CHECK(dql::contract::ParseRequest(MakeRequest("view.close", "null")).envelope.has_value());
	CHECK(!dql::contract::ParseRequest(MakeRequest("unknown", "null")).envelope.has_value());
	CHECK(!dql::contract::ParseRequest(MakeRequest("bootstrap", "\"scope-1\"")).envelope.has_value());
	CHECK(!dql::contract::ParseRequest(MakeRequest("pcv.status.read", "null")).envelope.has_value());
	CHECK(!dql::contract::ParseRequest(MakeRequest("view.close", "\"scope-1\"", "[]")).envelope.has_value());
	auto catalogRequest = dql::contract::ParseRequest(MakeRequest("correspondence.catalog.read", "\"scope-1\""));
	CHECK(catalogRequest.envelope.has_value());
	CHECK(dql::contract::ParseRequest(MakeRequest("correspondence.catalog.read", "\"scope-1\"", "{\"limit\":1,\"cursor\":null}")).envelope.has_value());
	CHECK(dql::contract::ParseRequest(MakeRequest("correspondence.catalog.read", "\"scope-1\"", "{\"limit\":100,\"cursor\":\"" + std::string(512, 'c') + "\"}")).envelope.has_value());
	CHECK(!dql::contract::ParseRequest(MakeRequest("correspondence.catalog.read", "\"scope-1\"", "{\"limit\":0}")).envelope.has_value());
	CHECK(!dql::contract::ParseRequest(MakeRequest("correspondence.catalog.read", "\"scope-1\"", "{\"limit\":101}")).envelope.has_value());
	CHECK(!dql::contract::ParseRequest(MakeRequest("correspondence.catalog.read", "\"scope-1\"", "{\"limit\":1.5}")).envelope.has_value());
	CHECK(!dql::contract::ParseRequest(MakeRequest("correspondence.catalog.read", "\"scope-1\"", "{\"limit\":\"50\"}")).envelope.has_value());
	CHECK(!dql::contract::ParseRequest(MakeRequest("correspondence.catalog.read", "\"scope-1\"", "{\"cursor\":\"" + std::string(513, 'c') + "\"}")).envelope.has_value());
	CHECK(!dql::contract::ParseRequest(MakeRequest("correspondence.catalog.read", "\"scope-1\"", "{\"cursor\":false}")).envelope.has_value());
	CHECK(!dql::contract::ParseRequest(MakeRequest("correspondence.catalog.read", "\"scope-1\"", "{\"search\":\"Lydia\"}")).envelope.has_value());

	auto duplicate = MakeRequest();
	duplicate.insert(1, "\"version\":1,");
	CHECK(dql::contract::ParseRequest(duplicate).errorCode == "duplicate_key");

	std::string tooDeep = "{}";
	for (int i = 0; i < 9; ++i) {
		tooDeep = "{\"x\":" + tooDeep + "}";
	}
	CHECK(dql::contract::ParseRequest(MakeRequest("bootstrap", "null", tooDeep)).errorCode == "too_deep");

	std::string tooMany = "{";
	for (int i = 0; i < 65; ++i) {
		if (i) tooMany += ',';
		tooMany += "\"k" + std::to_string(i) + "\":0";
	}
	tooMany += '}';
	CHECK(dql::contract::ParseRequest(MakeRequest("bootstrap", "null", tooMany)).errorCode == "too_many_properties");

	std::string tooManyArray = "[";
	for (int i = 0; i < 129; ++i) {
		if (i) tooManyArray += ',';
		tooManyArray += '0';
	}
	tooManyArray += ']';
	CHECK(dql::contract::ParseRequest(MakeRequest("bootstrap", "null", "{\"items\":" + tooManyArray + "}")).errorCode == "too_many_array_items");

	const std::string longValue(8'193, 'x');
	CHECK(dql::contract::ParseRequest(MakeRequest("bootstrap", "null", "{\"value\":\"" + longValue + "\"}")).errorCode == "string_too_large");
	CHECK(dql::contract::ParseRequest(std::string(65'537, ' ')).errorCode == "payload_too_large");

	const std::string invalidUtf8("\xC0\xAF", 2);
	CHECK(!dql::contract::ParseRequest(MakeRequest("bootstrap", "null", "{\"value\":\"" + invalidUtf8 + "\"}")).envelope.has_value());

	using dql::policy::NavigationCommand;
	using dql::policy::InputSource;
	CHECK(dql::policy::NormalizeInputMacro(InputSource::keyboard, 0x45) == 0x45);
	CHECK(dql::policy::NormalizeInputMacro(InputSource::gamepad, 266) == 266);
	CHECK(dql::policy::NormalizeInputMacro(InputSource::gamepad, 276) == 276);
	CHECK(dql::policy::NormalizeInputMacro(InputSource::gamepad, 0x0001) == std::nullopt);
	CHECK(dql::policy::NormalizeInputMacro(InputSource::keyboard, 276) == std::nullopt);
	CHECK(dql::policy::NormalizeInputMacro(InputSource::mouse, 0) == std::nullopt);
	CHECK(dql::policy::NormalizeInputMacro(InputSource::gamepad, 0x0003) == std::nullopt);
	CHECK(dql::policy::NormalizeInputMacro(InputSource::keyboard, 0x45) !=
		dql::policy::NormalizeInputMacro(InputSource::gamepad, 0x1000));
	CHECK(dql::policy::ShouldToggleOpenHotkey(false, true, InputSource::keyboard));
	CHECK(dql::policy::ShouldToggleOpenHotkey(false, true, InputSource::gamepad));
	CHECK(!dql::policy::ShouldToggleOpenHotkey(true, true, InputSource::keyboard));
	CHECK(!dql::policy::ShouldToggleOpenHotkey(true, true, InputSource::gamepad));
	CHECK(!dql::policy::ShouldToggleOpenHotkey(false, false, InputSource::keyboard));
	CHECK(dql::policy::ShouldNotifyUiBeforeNativeClose(false, true));
	CHECK(!dql::policy::ShouldNotifyUiBeforeNativeClose(true, true));
	CHECK(!dql::policy::ShouldNotifyUiBeforeNativeClose(false, false));
	CHECK(dql::policy::CanOpenView(true, false, false, false, false, false, false));
	CHECK(!dql::policy::CanOpenView(false, false, false, false, false, false, false));
	CHECK(!dql::policy::CanOpenView(true, true, false, false, false, false, false));
	CHECK(!dql::policy::CanOpenView(true, false, true, false, false, false, false));
	CHECK(!dql::policy::CanOpenView(true, false, false, true, false, false, false));
	CHECK(!dql::policy::CanOpenView(true, false, false, false, true, false, false));
	CHECK(!dql::policy::CanOpenView(true, false, false, false, false, true, false));
	CHECK(!dql::policy::CanOpenView(true, false, false, false, false, false, true));
	const dql::policy::NativeAccessState composeAccess{
		.serverContext = "scope-current", .authenticated = true, .compose = true, .correspondenceReady = true
	};
	CHECK(dql::policy::CanUseCorrespondence(composeAccess, "scope-current"));
	CHECK(!dql::policy::CanUseCorrespondence(composeAccess, "scope-old"));
	CHECK(!dql::policy::CanUseCorrespondence(composeAccess, ""));
	const dql::policy::NativeAccessState noCompose{
		.serverContext = "scope-current", .authenticated = true, .compose = false, .correspondenceReady = true
	};
	CHECK(!dql::policy::CanUseCorrespondence(noCompose, "scope-current"));
	const dql::policy::NativeAccessState unauthenticatedCompose{
		.serverContext = "scope-current", .authenticated = false, .compose = true, .correspondenceReady = true
	};
	CHECK(!dql::policy::CanUseCorrespondence(unauthenticatedCompose, "scope-current"));
	const dql::policy::NativeAccessState unavailableCorrespondence{
		.serverContext = "scope-current", .authenticated = true, .compose = true, .correspondenceReady = false
	};
	CHECK(!dql::policy::CanUseCorrespondence(unavailableCorrespondence, "scope-current"));
	const dql::policy::NativeAccessState pcvAccess{
		.serverContext = "scope-current", .authenticated = true, .pcv = true, .pcvReady = true
	};
	CHECK(dql::policy::CanSendCompanionPcv(pcvAccess, "scope-current"));
	CHECK(!dql::policy::CanSendCompanionPcv(pcvAccess, "scope-old"));
	const dql::policy::NativeAccessState noPcvRole{
		.serverContext = "scope-current", .authenticated = true, .pcv = false, .pcvReady = true
	};
	CHECK(!dql::policy::CanSendCompanionPcv(noPcvRole, "scope-current"));
	const dql::policy::NativeAccessState noPcvReadiness{
		.serverContext = "scope-current", .authenticated = true, .pcv = true, .pcvReady = false
	};
	CHECK(!dql::policy::CanSendCompanionPcv(noPcvReadiness, "scope-current"));
	const std::array<std::string_view, 6> managedNames{ "Serana", "  Lydia ", "SERANA", "", "M\xC3\xB6rke", std::string_view("bad\xC0\xAF", 5) };
	const auto managed = dql::policy::CollectManagedNames(managedNames);
	CHECK(managed.uniqueNames.size() == 2);
	CHECK(managed.uniqueNames[0] == "Lydia");
	CHECK(managed.uniqueNames[1] == "M\xC3\xB6rke");
	CHECK(managed.ambiguousNames.size() == 1 && managed.ambiguousNames[0] == "Serana");
	CHECK(managed.limited);
	const std::array unsafeManagedNames{
		std::string_view("Visible\nname"), std::string_view("Hidden\0suffix", 13), std::string_view("Safe name")
	};
	const auto safeManagedNames = dql::policy::CollectManagedNames(unsafeManagedNames);
	CHECK(safeManagedNames.uniqueNames.size() == 1 && safeManagedNames.uniqueNames[0] == "Safe name");
	CHECK(safeManagedNames.limited);
	const std::array overLimitNames{ std::string_view("A"), std::string_view("B"), std::string_view("C") };
	const auto overLimit = dql::policy::CollectManagedNames(overLimitNames, 2);
	CHECK(overLimit.uniqueNames.size() == 2 && overLimit.limited);
	CHECK(dql::policy::CapUtf8("M\xC3\xB6rke", 6) == std::string("M\xC3\xB6rke"));
	CHECK(dql::policy::CapUtf8("M\xC3\xB6rke", 2) == std::string("M"));
	CHECK(!dql::policy::CapUtf8(std::string_view("\xC0\xAF", 2), 8));
	CHECK(dql::policy::IsAllowedPlayerText("First line\nSecond line"));
	CHECK(!dql::policy::IsAllowedPlayerText(std::string_view("a\0b", 3)));
	CHECK(!dql::policy::IsAllowedPlayerText(std::string(8'001, 'x')));
	CHECK(!dql::policy::IsAllowedPlayerText(std::string(2'001, 'x')));
	CHECK(dql::policy::IsUuid("123e4567-e89b-12d3-a456-426614174000"));
	CHECK(!dql::policy::IsUuid("123e4567e89b12d3a456426614174000"));
	CHECK(dql::policy::IsGeneration("18446744073709551615"));
	CHECK(!dql::policy::IsGeneration("18446744073709551616x"));
	CHECK(dql::policy::IsSafeHeaderValue("abc-123"));
	CHECK(!dql::policy::IsSafeHeaderValue("ok\r\nInjected: yes"));
	CHECK(dql::policy::CanonicalBaseFormId("Skyrim.esm", 0x0000000F, false) == "Skyrim.esm|0000000F");
	CHECK(dql::policy::CanonicalBaseFormId("Items.esl", 0x00000ABC, false) == "Items.esl|00000ABC");
	std::string maxBaseName(64, 'A');
	maxBaseName += ".esp";
	CHECK(dql::policy::CanonicalBaseFormId(maxBaseName, 0x00000ABC, false));
	std::string oversizedBaseName(65, 'A');
	oversizedBaseName += ".esp";
	CHECK(!dql::policy::CanonicalBaseFormId(oversizedBaseName, 0x00000ABC, false));
	CHECK(!dql::policy::CanonicalBaseFormId("Items.esl", 0xFF000001, true));
	CHECK(!dql::policy::CanonicalBaseFormId("../Items.esl", 0x00000ABC, false));
	CHECK(!dql::policy::CanonicalBaseFormId("Bad!Name.esm", 0x00000ABC, false));
	CHECK(!dql::policy::CanonicalBaseFormId(" Items.esm", 0x00000ABC, false));
	CHECK(!dql::policy::CanonicalBaseFormId("Items.dll", 0x00000ABC, false));
	CHECK(!dql::policy::CanonicalBaseFormId(std::string_view("Items.esl\nInjected"), 0x00000ABC, false));
	CHECK(dql::policy::MapGamepadButton(266) == NavigationCommand::up);
	CHECK(dql::policy::MapGamepadButton(267) == NavigationCommand::down);
	CHECK(dql::policy::MapGamepadButton(268) == NavigationCommand::left);
	CHECK(dql::policy::MapGamepadButton(269) == NavigationCommand::right);
	CHECK(dql::policy::MapGamepadButton(271) == NavigationCommand::back);
	CHECK(dql::policy::MapGamepadButton(274) == NavigationCommand::previous);
	CHECK(dql::policy::MapGamepadButton(275) == NavigationCommand::next);
	CHECK(dql::policy::MapGamepadButton(276) == NavigationCommand::activate);
	CHECK(dql::policy::MapGamepadButton(277) == NavigationCommand::back);
	CHECK(!dql::policy::MapGamepadButton(270));
	CHECK(!dql::policy::MapGamepadButton(288));

	dql::policy::InventoryTraits vanillaStack{ .count = 4, .stableForm = true, .inventoryObject = true };
	CHECK(dql::policy::IsTransferableStack(vanillaStack));
	const std::array countOnlyTypes{ 0x24U, 0x24U };
	CHECK(dql::policy::ClassifyExtraData(countOnlyTypes) == dql::policy::InstanceMetadata::countOnly);
	auto countedStack = vanillaStack;
	countedStack.instanceMetadata = dql::policy::ClassifyExtraData(countOnlyTypes);
	CHECK(dql::policy::IsTransferableStack(countedStack));
	const std::array variantTypes{ 0x24U, 0x99U };
	CHECK(dql::policy::ClassifyExtraData(variantTypes) == dql::policy::InstanceMetadata::unsafe);
	auto ambiguousStack = vanillaStack;
	ambiguousStack.instanceMetadata = dql::policy::ClassifyExtraData(variantTypes);
	CHECK(!dql::policy::IsTransferableStack(ambiguousStack));
	const std::array unknownTypes{ 0x1234U };
	CHECK(dql::policy::ClassifyExtraData(unknownTypes) == dql::policy::InstanceMetadata::unsafe);
	auto questStack = vanillaStack;
	questStack.questObject = true;
	CHECK(!dql::policy::IsTransferableStack(questStack));
	auto equippedStack = vanillaStack;
	equippedStack.equipped = true;
	CHECK(!dql::policy::IsTransferableStack(equippedStack));
	auto enchantedStack = vanillaStack;
	enchantedStack.enchanted = true;
	CHECK(!dql::policy::IsTransferableStack(enchantedStack));
	auto unpinnedStack = vanillaStack;
	unpinnedStack.stableForm = false;
	CHECK(!dql::policy::IsTransferableStack(unpinnedStack));
	auto dynamicStack = vanillaStack;
	dynamicStack.dynamicForm = true;
	CHECK(!dql::policy::IsTransferableStack(dynamicStack));
	auto favoritedStack = vanillaStack;
	favoritedStack.favorited = true;
	CHECK(!dql::policy::IsTransferableStack(favoritedStack));
	auto poisonedStack = vanillaStack;
	poisonedStack.poisoned = true;
	CHECK(!dql::policy::IsTransferableStack(poisonedStack));
	auto leveledStack = vanillaStack;
	leveledStack.leveled = true;
	CHECK(!dql::policy::IsTransferableStack(leveledStack));
	auto emptyStack = vanillaStack;
	emptyStack.count = 0;
	CHECK(!dql::policy::IsTransferableStack(emptyStack));
	using dql::policy::TransferLine;
	const std::array validLines{
		TransferLine{ "123e4567-e89b-12d3-a456-426614174000", 2, 3 },
		TransferLine{ "123e4567-e89b-12d3-a456-426614174001", 1, 1 }
	};
	CHECK(dql::policy::ValidateTransferLines(validLines, 4, 10));
	CHECK(dql::policy::ValidateTransferLines(validLines, 0, 0));
	CHECK(!dql::policy::ValidateTransferLines(validLines, 11, 10));
	CHECK(!dql::policy::ValidateTransferLines(validLines, -1, 10));
	const std::array duplicateLines{
		TransferLine{ "123e4567-e89b-12d3-a456-426614174000", 1, 3 },
		TransferLine{ "123e4567-e89b-12d3-a456-426614174000", 1, 3 }
	};
	CHECK(!dql::policy::ValidateTransferLines(duplicateLines, 0, 0));
	const std::array staleLines{ TransferLine{ "123e4567-e89b-12d3-a456-426614174000", 4, 3 } };
	CHECK(!dql::policy::ValidateTransferLines(staleLines, 0, 0));
	const std::array zeroQuantity{ TransferLine{ "123e4567-e89b-12d3-a456-426614174000", 0, 3 } };
	CHECK(!dql::policy::ValidateTransferLines(zeroQuantity, 0, 0));
	const std::array invalidKey{ TransferLine{ "opaque-but-not-uuid", 1, 3 } };
	CHECK(!dql::policy::ValidateTransferLines(invalidKey, 0, 0));
	const std::array eightLines{
		TransferLine{ "123e4567-e89b-12d3-a456-426614174000", 1, 1 },
		TransferLine{ "123e4567-e89b-12d3-a456-426614174001", 1, 1 },
		TransferLine{ "123e4567-e89b-12d3-a456-426614174002", 1, 1 },
		TransferLine{ "123e4567-e89b-12d3-a456-426614174003", 1, 1 },
		TransferLine{ "123e4567-e89b-12d3-a456-426614174004", 1, 1 },
		TransferLine{ "123e4567-e89b-12d3-a456-426614174005", 1, 1 },
		TransferLine{ "123e4567-e89b-12d3-a456-426614174006", 1, 1 },
		TransferLine{ "123e4567-e89b-12d3-a456-426614174007", 1, 1 }
	};
	CHECK(dql::policy::ValidateTransferLines(eightLines, 0, 0));
	CHECK(!dql::policy::ValidateTransferLines(eightLines, 1, 10));

	auto response = dql::contract::MakeResponse(*good.envelope, Json{{"authenticated", false}});
	CHECK(response["requestId"] == kToken);
	CHECK(response["context"]["serverContext"].is_null());
	CHECK(response["ok"] == true);
	auto echoed = dql::contract::ParseServerResponse(response.dump(), *good.envelope);
	CHECK(echoed.value.has_value());
	CHECK((*echoed.value)["context"]["serverContext"].is_null());
	CHECK((*echoed.value)["data"]["authenticated"] == false);
	const auto catalogResponse = dql::contract::MakeResponse(*catalogRequest.envelope, MakeCatalogData());
	CHECK(dql::contract::ParseServerResponse(catalogResponse.dump(), *catalogRequest.envelope).value.has_value());
	CHECK(dql::contract::ParseServerResponse(dql::contract::MakeResponse(*catalogRequest.envelope, MakeCatalogData(100)).dump(), *catalogRequest.envelope).value.has_value());
	auto catalogWithCursor = catalogResponse;
	catalogWithCursor["data"]["nextCursor"] = std::string(512, 'c');
	CHECK(dql::contract::ParseServerResponse(catalogWithCursor.dump(), *catalogRequest.envelope).value.has_value());
	catalogWithCursor["data"]["nextCursor"] = std::string(513, 'c');
	CHECK(!dql::contract::ParseServerResponse(catalogWithCursor.dump(), *catalogRequest.envelope).value.has_value());
	auto validCatalogKeyBoundaries = catalogResponse;
	validCatalogKeyBoundaries["data"]["actors"][0]["actorKey"] = "npc:1234567890123456789";
	validCatalogKeyBoundaries["data"]["actors"][0]["label"] = std::string(256, 'L');
	CHECK(dql::contract::ParseServerResponse(validCatalogKeyBoundaries.dump(), *catalogRequest.envelope).value.has_value());
	validCatalogKeyBoundaries["data"]["actors"][0]["actorKey"] = "player";
	CHECK(dql::contract::ParseServerResponse(validCatalogKeyBoundaries.dump(), *catalogRequest.envelope).value.has_value());
	auto badCatalogRevision = catalogResponse;
	badCatalogRevision["data"]["revision"] = std::string(64, 'A');
	CHECK(!dql::contract::ParseServerResponse(badCatalogRevision.dump(), *catalogRequest.envelope).value.has_value());
	auto extraCatalogField = catalogResponse;
	extraCatalogField["data"]["search"] = "unsupported";
	CHECK(!dql::contract::ParseServerResponse(extraCatalogField.dump(), *catalogRequest.envelope).value.has_value());
	auto badCatalogActorKey = catalogResponse;
	badCatalogActorKey["data"]["actors"][0]["actorKey"] = "npc:01";
	CHECK(!dql::contract::ParseServerResponse(badCatalogActorKey.dump(), *catalogRequest.envelope).value.has_value());
	auto badCatalogLabel = catalogResponse;
	badCatalogLabel["data"]["actors"][0]["label"] = std::string(257, 'L');
	CHECK(!dql::contract::ParseServerResponse(badCatalogLabel.dump(), *catalogRequest.envelope).value.has_value());
	auto tooManyCatalogActors = dql::contract::MakeResponse(*catalogRequest.envelope, MakeCatalogData(101));
	CHECK(!dql::contract::ParseServerResponse(tooManyCatalogActors.dump(), *catalogRequest.envelope).value.has_value());
	auto oversizedCatalogPage = dql::contract::MakeResponse(*catalogRequest.envelope, MakeCatalogData(30, std::string(256, '\0')));
	CHECK(oversizedCatalogPage.dump().size() < dql::contract::kMaxEnvelopeBytes);
	CHECK(!dql::contract::ParseServerResponse(oversizedCatalogPage.dump(), *catalogRequest.envelope).value.has_value());
	auto badCatalogActorFields = catalogResponse;
	badCatalogActorFields["data"]["actors"][0]["canWrite"] = "true";
	CHECK(!dql::contract::ParseServerResponse(badCatalogActorFields.dump(), *catalogRequest.envelope).value.has_value());
	auto staleEcho = response;
	staleEcho["context"]["nativeGeneration"] = "43";
	CHECK(!dql::contract::ParseServerResponse(staleEcho.dump(), *good.envelope).value.has_value());
	auto serverError = dql::contract::MakeError(*good.envelope, "unauthorized", "Pairing is required.");
	CHECK(dql::contract::ParseServerResponse(serverError.dump(), *good.envelope).value.has_value());
	auto knownNoDispatch = dql::contract::MakeError(*good.envelope, "unavailable", "Not submitted.", false);
	CHECK(knownNoDispatch["error"]["dispatched"] == false);
	CHECK(!serverError["error"].contains("dispatched"));
	serverError["error"]["message"] = std::string(257, 'x');
	CHECK(!dql::contract::ParseServerResponse(serverError.dump(), *good.envelope).value.has_value());
	std::cout << "contract tests passed\n";
	} catch (const std::exception& error) {
		std::cerr << error.what() << '\n';
		return 1;
	}
}
