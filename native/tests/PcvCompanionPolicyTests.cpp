#include "../src/PcvCompanionPolicy.h"

#include <cstdlib>
#include <fstream>
#include <iostream>
#include <stdexcept>
#include <string>

#define CHECK(condition) do { if (!(condition)) throw std::runtime_error("check failed: " #condition); } while (false)

namespace
{
	using dql::companion::DispatchGuard;
	using dql::companion::DispatchResult;

	DispatchGuard GoodGuard()
	{
		return {
			.requestCurrent = true,
			.actorKeyMapped = true,
			.actorAlive = true,
			.expectedName = "Serana",
			.expectedFormId = 0x00001234,
			.currentName = "Serana",
			.currentFormId = 0x00001234
		};
	}

	constexpr std::string_view kExplicitIni =
		"SERVER=127.0.0.1\r\nPORT=8081\r\nPATH=/HerikaServer/comm.php\r\nPOLINT=1\r\n";

	dql::contract::Json Status(std::string state = "prepared", std::string active = "123e4567-e89b-12d3-a456-426614174000",
		std::string pending = "123e4567-e89b-12d3-a456-426614174001")
	{
		return {
			{ "operationId", "123e4567-e89b-12d3-a456-426614174002" },
			{ "state", std::move(state) },
			{ "expected", { { "activeConfigId", std::move(active) }, { "pendingConfigId", std::move(pending) } } },
			{ "target", { { "formId", "00001234" }, { "name", "Serana" } } },
			{ "expiresAt", 1'798'000'000 }
		};
	}

	void CheckPhpSerializerFixtures()
	{
		const char* path = std::getenv("DQL_PCV_COMPANION_FIXTURE_PATH");
		CHECK(path && *path);
		std::ifstream input(path, std::ios::binary);
		CHECK(input.is_open());
		dql::contract::Json fixture;
		input >> fixture;
		CHECK(fixture.is_object());
		CHECK(fixture.contains("stage") && fixture.contains("preparedStatus") &&
			fixture.contains("claimedUnknownStatus") && fixture.contains("consumedStatus") &&
			fixture.contains("missingUnknownStatus"));

		const auto& stage = fixture.at("stage");
		const auto operationId = stage.at("operationId").get<std::string>();
		CHECK(dql::companion::IsValidStageData(stage, operationId));
		const auto& preparedStatus = fixture.at("preparedStatus");
		CHECK(!preparedStatus.contains("token") && !preparedStatus.contains("text"));
		CHECK(preparedStatus.at("expected").at("activeConfigId").is_null());
		CHECK(preparedStatus.at("expected").at("pendingConfigId").is_string());
		const dql::companion::ExpectedConfigIds expected{
			.active = std::nullopt,
			.pending = preparedStatus.at("expected").at("pendingConfigId").get<std::string>()
		};
		const dql::companion::TargetIdentity target{ dql::companion::FormIdHex(0x00F00123), "Lydia" };
		CHECK(target.formId == preparedStatus.at("target").at("formId").get<std::string>());
		CHECK(target.name == preparedStatus.at("target").at("name").get<std::string>());
		const auto prepared = dql::companion::ProjectStatus(preparedStatus, operationId, expected, target);
		CHECK(prepared && prepared->state == "prepared" && prepared->target == target && prepared->expected == expected);

		const auto& claimedUnknown = fixture.at("claimedUnknownStatus");
		CHECK(!claimedUnknown.contains("token") && !claimedUnknown.contains("text"));
		const auto claimedProjection = dql::companion::ProjectStatus(claimedUnknown, operationId, expected, target);
		CHECK(claimedProjection && claimedProjection->state == "unknown" && !claimedProjection->target &&
			!claimedProjection->expected.active && !claimedProjection->expected.pending && !claimedProjection->expiresAt);

		const auto& consumedStatus = fixture.at("consumedStatus");
		CHECK(!consumedStatus.contains("token") && !consumedStatus.contains("text"));
		const auto consumed = dql::companion::ProjectStatus(consumedStatus, operationId, expected, target);
		CHECK(consumed && consumed->state == "consumed" && consumed->target == target && consumed->expected == expected);

		const auto& missingUnknown = fixture.at("missingUnknownStatus");
		const auto missingId = missingUnknown.at("operationId").get<std::string>();
		const auto missing = dql::companion::ProjectStatus(missingUnknown, missingId, std::nullopt, std::nullopt);
		CHECK(missing && missing->state == "unknown" && !missing->target &&
			!missing->expected.active && !missing->expected.pending && !missing->expiresAt);
	}

	void CheckFreshRosterActorMatches()
	{
		int mapped{};
		int duplicate{};
		int other{};

		dql::companion::FreshActorNameMatch unique("Serana", &mapped);
		CHECK(unique.Observe(&mapped, "\tSERANA\r\n"));
		CHECK(unique.IsUniqueMappedActor());

		dql::companion::FreshActorNameMatch ambiguous("Serana", &mapped);
		CHECK(ambiguous.Observe(&mapped, "Serana"));
		CHECK(ambiguous.Observe(&duplicate, " SERANA "));
		CHECK(!ambiguous.IsUniqueMappedActor()); // original and another fresh actor share the managed name

		dql::companion::FreshActorNameMatch wrongActor("Serana", &mapped);
		CHECK(wrongActor.Observe(&other, "Serana"));
		CHECK(!wrongActor.IsUniqueMappedActor()); // name fallback cannot substitute a different mapped actor
		CHECK(!wrongActor.Observe(&mapped, "SeranaX"));
		CHECK(!wrongActor.IsUniqueMappedActor());
	}
}

int main()
{
	try {
		const std::string token = "pcv1_" + std::string(43, 'A');
		CHECK(dql::companion::IsValidOpaqueToken(token));
		CHECK(!dql::companion::IsValidOpaqueToken("pcv1_short"));
		CHECK(!dql::companion::IsValidOpaqueToken("pcv1_" + std::string(42, 'A') + "="));
		CHECK(!dql::companion::IsValidOpaqueToken("xpcv1_" + std::string(43, 'A')));
		CHECK(dql::companion::IsValidPairingToken("qd1_" + std::string(43, 'A')));
		CHECK(!dql::companion::IsValidPairingToken("qd1_" + std::string(42, 'A')));
		CHECK(!dql::companion::IsValidPairingToken("qd1_" + std::string(42, 'A') + "+"));
		CHECK(dql::companion::IsFormId("00001234"));
		CHECK(dql::companion::IsFormId("aBcD1234"));
		CHECK(!dql::companion::IsFormId("1234"));
		CHECK(!dql::companion::IsFormId("0000123g"));
		CHECK(dql::companion::NamesMatchManagedActor("lydia", "Lydia"));
		CHECK(dql::companion::NamesMatchManagedActor("Lydia", "Lydia "));
		CHECK(dql::companion::NamesMatchManagedActor("\t\xC3\x89LODIE \xC3\x81vila\r\n", "\xC3\x89lodie \xC3\x81vila"));
		CheckFreshRosterActorMatches();
		CHECK(dql::companion::FormIdHex(0x00001234) == "00001234");
		CHECK(dql::companion::FormIdHex(0xFFFFFFFF) == "ffffffff");
		CHECK(dql::companion::FormIdHex(0x00F00123) == "00f00123");
		CHECK(dql::companion::CanUseStockDestination(kExplicitIni, kExplicitIni,
			"http://127.0.0.1:8081/HerikaServer"));
		const auto derivedBase = dql::companion::DeriveStockServerBase(kExplicitIni);
		CHECK(derivedBase && *derivedBase == "http://127.0.0.1:8081/HerikaServer");
		CHECK(dql::companion::IsExactHttpDestination("HTTP://127.0.0.1:08081/HerikaServer/", *derivedBase));
		CHECK(!dql::companion::IsExactHttpDestination("http://127.0.0.1:8082/HerikaServer", *derivedBase));
		CHECK(!dql::companion::IsExactHttpDestination("https://127.0.0.1:8081/HerikaServer", *derivedBase));
		const auto remoteIni = "SERVER=chim.example.test\r\nPORT=80\r\nPATH=/HerikaServer/comm.php\r\n";
		const auto remoteBase = dql::companion::DeriveStockServerBase(remoteIni);
		CHECK(remoteBase && *remoteBase == "http://chim.example.test:80/HerikaServer");
		const auto customPathIni = "SERVER=chim.example.test\r\nPORT=80\r\nPATH=/custom/chim/comm.php\r\n";
		const auto customPathBase = dql::companion::DeriveStockServerBase(customPathIni);
		CHECK(customPathBase && *customPathBase == "http://chim.example.test:80/custom/chim");
		const auto rootPathIni = "SERVER=chim.example.test\r\nPORT=80\r\nPATH=/comm.php\r\n";
		const auto rootPathBase = dql::companion::DeriveStockServerBase(rootPathIni);
		CHECK(rootPathBase && *rootPathBase == "http://chim.example.test:80");
		const auto discoveredBase = dql::companion::DeriveChimDiscoveryServerBase("172.19.128.4:8081\r\n");
		CHECK(discoveredBase && *discoveredBase == "http://172.19.128.4:8081/HerikaServer");
		const auto normalizedDiscovery = dql::companion::DeriveChimDiscoveryServerBase("001.002.003.004:08081");
		CHECK(normalizedDiscovery && *normalizedDiscovery == "http://1.2.3.4:8081/HerikaServer");
		CHECK(!dql::companion::DeriveChimDiscoveryServerBase("2001:db8::1:8081"));
		CHECK(!dql::companion::DeriveChimDiscoveryServerBase("256.1.2.3:8081"));
		CHECK(!dql::companion::DeriveChimDiscoveryServerBase("172.19.128.4:0"));
		CHECK(!dql::companion::DeriveChimDiscoveryServerBase("172.19.128.4:8081/other"));
		CHECK(!dql::companion::DeriveChimDiscoveryServerBase("172.19.128.4:8081\r\nAuthorization: Bearer secret"));
		CHECK(!dql::companion::DeriveStockServerBase("SERVER=chim.example.test\r\nPORT=0\r\n"));
		CHECK(!dql::companion::DeriveStockServerBase("SERVER=chim.example.test\r\nPORT=8081\r\nPATH=/Other/index.php\r\n"));
		CHECK(!dql::companion::DeriveStockServerBase("SERVER=chim.example.test\r\nPORT=8081\r\nPATH=/HerikaServer/../comm.php\r\n"));
		CHECK(dql::companion::CanUseStockDestination(kExplicitIni, kExplicitIni,
			"HTTP://127.0.0.1:08081/HerikaServer/"));
		CHECK(!dql::companion::CanUseStockDestination({}, {}, "http://127.0.0.1:8081/HerikaServer"));
		CHECK(!dql::companion::CanUseStockDestination(kExplicitIni, kExplicitIni,
			"http://127.0.0.1:8082/HerikaServer"));
		CHECK(!dql::companion::CanUseStockDestination(kExplicitIni, kExplicitIni,
			"http://other.test:8081/HerikaServer"));
		CHECK(!dql::companion::CanUseStockDestination(kExplicitIni, kExplicitIni,
			"http://127.0.0.1:8081/Other"));
		const std::string editedIni = std::string(kExplicitIni) + "# changed after startup\r\n";
		CHECK(!dql::companion::CanUseStockDestination(kExplicitIni, editedIni,
			"http://127.0.0.1:8081/HerikaServer"));
		const std::string missingHost = "SERVER=\r\nPORT=8081\r\n";
		CHECK(!dql::companion::CanUseStockDestination(missingHost, missingHost,
			"http://127.0.0.1:8081/HerikaServer"));

		bool called = false;
		int fakeActor{};
		const auto accepted = dql::companion::DispatchStockPlayerInput(token, GoodGuard(),
			&fakeActor,
			[&](std::string_view papyrusClass, std::string_view function, std::string_view text, std::string_view type,
				void* actor) {
				called = true;
				CHECK(papyrusClass == "AIAgentFunctions");
				CHECK(function == "sendMessageToActor");
				CHECK(text == token);
				CHECK(type == "inputtext");
				CHECK(actor == &fakeActor);
				return true;
			});
		CHECK(accepted == DispatchResult::queueAccepted && called);

		called = false;
		auto stale = GoodGuard();
		stale.requestCurrent = false;
		CHECK(dql::companion::DispatchStockPlayerInput(token, stale, &fakeActor, [&](auto&&...) { called = true; return true; }) ==
			DispatchResult::notAttempted);
		CHECK(!called);
		stale = GoodGuard();
		stale.currentName = "SeranaX";
		CHECK(dql::companion::DispatchStockPlayerInput(token, stale, &fakeActor, [&](auto&&...) { called = true; return true; }) ==
			DispatchResult::notAttempted);
		CHECK(!called);
		stale = GoodGuard();
		stale.currentFormId = 0x00005678;
		CHECK(dql::companion::DispatchStockPlayerInput(token, stale, &fakeActor, [&](auto&&...) { called = true; return true; }) ==
			DispatchResult::notAttempted);
		CHECK(!called);
		stale = GoodGuard();
		stale.actorKeyMapped = false;
		CHECK(dql::companion::DispatchStockPlayerInput(token, stale, &fakeActor, [&](auto&&...) { called = true; return true; }) ==
			DispatchResult::notAttempted);
		CHECK(!called);
		stale = GoodGuard();
		stale.actorAlive = false;
		CHECK(dql::companion::DispatchStockPlayerInput(token, stale, &fakeActor, [&](auto&&...) { called = true; return true; }) ==
			DispatchResult::notAttempted);
		CHECK(!called);
		CHECK(dql::companion::DispatchStockPlayerInput("ordinary user text", GoodGuard(), &fakeActor,
			[&](auto&&...) { called = true; return true; }) == DispatchResult::notAttempted);
		CHECK(!called);
		CHECK(dql::companion::DispatchStockPlayerInput(token, GoodGuard(), &fakeActor,
			[](auto&&...) { return false; }) == DispatchResult::queueNotConfirmed);

		const std::string operationId = "123e4567-e89b-12d3-a456-426614174002";
		const auto stage = dql::contract::Json{
			{ "operationId", operationId }, { "state", "prepared" }, { "token", token }, { "expiresAt", 1'798'000'000 }
		};
		CHECK(dql::companion::IsValidStageData(stage, operationId));
		CHECK(dql::companion::IsFreshStageData(stage, operationId, 1'797'999'999));
		CHECK(!dql::companion::IsFreshStageData(stage, operationId, 1'798'000'000));
		CHECK(!dql::companion::IsFreshStageData(stage, operationId, 1'798'000'001));
		CHECK(!dql::companion::IsValidStageData(stage, "123e4567-e89b-12d3-a456-426614174003"));
		auto badStage = stage;
		badStage["state"] = "consumed";
		CHECK(!dql::companion::IsValidStageData(badStage, operationId));
		badStage = stage;
		badStage["expiresAt"] = nullptr;
		CHECK(!dql::companion::IsValidStageData(badStage, operationId));
		badStage["expiresAt"] = 0;
		CHECK(!dql::companion::IsValidStageData(badStage, operationId));
		badStage = stage;
		badStage["extra"] = "literal text must not escape";
		CHECK(!dql::companion::IsValidStageData(badStage, operationId));

		const dql::companion::ExpectedConfigIds original{
			.active = "123e4567-e89b-12d3-a456-426614174000",
			.pending = "123e4567-e89b-12d3-a456-426614174001"
		};
		const dql::companion::TargetIdentity target{ "00001234", "Serana" };
		const auto projection = dql::companion::ProjectStatus(Status(), operationId, original, target);
		CHECK(projection.has_value());
		CHECK(projection->operationId == operationId && projection->state == "prepared");
		auto rawTargetStatus = Status();
		rawTargetStatus["target"]["name"] = "  \xC3\x89LODIE \xC3\x81vila  ";
		const dql::companion::TargetIdentity rawTarget{ "00001234", "  \xC3\x89LODIE \xC3\x81vila  " };
		const auto rawProjection = dql::companion::ProjectStatus(rawTargetStatus, operationId, original, rawTarget);
		CHECK(rawProjection && rawProjection->target == rawTarget);
		CHECK(rawProjection->target->name == "  \xC3\x89LODIE \xC3\x81vila  ");
		CHECK(projection->expected == original);
		CHECK(projection->target == target && projection->expiresAt == 1'798'000'000);
		auto consumed = Status("consumed");
		CHECK(dql::companion::ProjectStatus(consumed, operationId, original, target)->state == "consumed");
		CHECK(!dql::companion::ProjectStatus(Status("delivered"), operationId, original, target));
		CHECK(!dql::companion::ProjectStatus(Status(), "123e4567-e89b-12d3-a456-426614174003", original, target));
		CHECK(!dql::companion::ProjectStatus(Status("prepared", "123e4567-e89b-12d3-a456-426614174009"), operationId, original, target));
		CHECK(!dql::companion::ProjectStatus(Status(), operationId, original, dql::companion::TargetIdentity{ "00005678", "Serana" }));
		auto unknown = Status("unknown");
		unknown["expected"] = { { "activeConfigId", nullptr }, { "pendingConfigId", nullptr } };
		unknown["target"] = nullptr;
		unknown["expiresAt"] = nullptr;
		const auto unknownProjection = dql::companion::ProjectStatus(unknown, operationId, original, target);
		CHECK(unknownProjection && unknownProjection->state == "unknown");
		CHECK(!unknownProjection->expected.active && !unknownProjection->expected.pending);
		auto malformedKnownTarget = Status();
		malformedKnownTarget["target"]["formId"] = nullptr;
		malformedKnownTarget["target"]["name"] = nullptr;
		CHECK(!dql::companion::ProjectStatus(malformedKnownTarget, operationId, original, target));
		CheckPhpSerializerFixtures();
		std::cout << "PCV companion policy tests passed\n";
		return 0;
	} catch (const std::exception& error) {
		std::cerr << error.what() << '\n';
		return 1;
	}
}
