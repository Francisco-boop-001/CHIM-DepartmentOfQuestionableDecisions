#include "../src/ConnectionProtocol.h"

#include <cstdlib>
#include <fstream>
#include <iterator>
#include <stdexcept>
#include <string>

#define CHECK(condition) do { if (!(condition)) throw std::runtime_error("check failed: " #condition); } while (false)

namespace
{
	void WriteClaimEventFixtureIfRequested(const dql::connection::ClaimEvent& event)
	{
		const char* path = std::getenv("DQL_CONNECTION_EVENT_FIXTURE_PATH");
		if (!path || !*path) return;

		const dql::contract::Json fixture{
			{ "claimId", event.claimId }, { "tokenHash", event.tokenHash },
			{ "message", event.message }, { "type", event.type }
		};
		std::ofstream output(path, std::ios::binary | std::ios::trunc);
		CHECK(output.is_open());
		output << fixture.dump(2) << '\n';
		CHECK(output.good());
	}

	void CheckServerFixturesIfRequested()
	{
		const char* path = std::getenv("DQL_CONNECTION_FIXTURES_PATH");
		if (!path || !*path) return;

		std::ifstream input(path, std::ios::binary);
		CHECK(input.is_open());
		const std::string raw((std::istreambuf_iterator<char>(input)), std::istreambuf_iterator<char>());
		const auto parsed = dql::contract::ParseBoundedJson(raw);
		CHECK(parsed.value && parsed.value->is_object() && parsed.value->size() == 4);
		const auto& fixture = *parsed.value;
		CHECK(fixture.contains("beginPending") && fixture.contains("beginApproved") &&
			fixture.contains("beginExpired") && fixture.contains("statusApproved"));
		const auto checkResponse = [&fixture](const char* key, std::string_view expectedState) {
			const auto& response = fixture.at(key);
			CHECK(response.is_object() && response.contains("claimId") && response.at("claimId").is_string());
			const auto claimId = response.at("claimId").get<std::string>();
			const auto result = dql::connection::ParseClaimResponse(response, claimId);
			CHECK(result && result->state == expectedState);
		};
		checkResponse("beginPending", "pending");
		checkResponse("beginApproved", "approved");
		checkResponse("beginExpired", "expired");
		checkResponse("statusApproved", "approved");
	}
}

int main()
{
	try {
		const std::string claimId = "123e4567-e89b-12d3-a456-426614174000";
		const auto begin = dql::connection::MakeBeginRequest(claimId, std::string(64, 'a'));
		const dql::contract::Json expectedBegin{
			{ "action", "begin" }, { "claimId", std::string(claimId) }, { "tokenHash", std::string(64, 'a') }
		};
		CHECK(begin == expectedBegin);
		const auto status = dql::connection::MakeStatusRequest(claimId);
		const dql::contract::Json expectedStatus{ { "action", "status" }, { "claimId", std::string(claimId) } };
		CHECK(status == expectedStatus);

		const auto event = dql::connection::MakeClaimEvent(claimId, std::string(64, 'a'));
		CHECK(event && event->message ==
			"dql_connect_v1:123e4567-e89b-12d3-a456-426614174000:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa");
		CHECK(event->claimId == claimId);
		CHECK(event->tokenHash == std::string(64, 'a'));
		CHECK(event->type == "physics_raw" && event->type == dql::connection::kProducerType);
		WriteClaimEventFixtureIfRequested(*event);
		CHECK(!dql::connection::MakeClaimEvent("123E4567-e89b-12d3-a456-426614174000", std::string(64, 'a')));
		CHECK(!dql::connection::MakeClaimEvent(claimId, std::string(63, 'a')));
		CHECK(!dql::connection::MakeClaimEvent(claimId, std::string(63, 'a') + "A"));

		const auto pending = dql::connection::ParseClaimResponse(
			{ { "ok", true }, { "state", "pending" }, { "claimId", claimId } }, claimId);
		CHECK(pending && pending->state == "pending");
		const auto approved = dql::connection::ParseClaimResponse(
			{ { "ok", true }, { "state", "approved" }, { "claimId", claimId } }, claimId);
		CHECK(approved && approved->state == "approved");
		const auto expiredBegin = dql::connection::ParseClaimResponse(
			{ { "ok", true }, { "state", "expired" }, { "claimId", claimId } }, claimId);
		CHECK(expiredBegin && expiredBegin->state == "expired");
		const auto expired = dql::connection::ParseClaimResponse(
			{ { "ok", true }, { "state", "expired" }, { "claimId", claimId } }, claimId);
		CHECK(expired && expired->state == "expired");

		CHECK(!dql::connection::ParseClaimResponse(
			{ { "ok", true }, { "state", "pending" }, { "claimId", "223e4567-e89b-12d3-a456-426614174000" } }, claimId));
		CHECK(!dql::connection::ParseClaimResponse(
			{ { "ok", true }, { "state", "pending" }, { "claimId", "123E4567-e89b-12d3-a456-426614174000" } }, claimId));
		CHECK(!dql::connection::ParseClaimResponse(
			{ { "ok", true }, { "state", "pending" }, { "claimId", claimId },
				{ "approvalPath", "https://evil.example/" } }, claimId));
		CHECK(!dql::connection::ParseClaimResponse(
			{ { "ok", true }, { "state", "approved" }, { "claimId", claimId },
				{ "approvalPath", "/ext/questionable_decisions/index.php?claim=" + claimId } }, claimId));
		CHECK(!dql::connection::ParseClaimResponse(
			{ { "ok", false }, { "state", "pending" }, { "claimId", claimId } }, claimId));
		CHECK(!dql::connection::ParseClaimResponse(
			{ { "ok", true }, { "state", "waiting" }, { "claimId", claimId } }, claimId));
		CHECK(!dql::connection::ParseClaimResponse(
			{ { "ok", true }, { "state", "pending" }, { "claimId", claimId }, { "extra", "value" } }, claimId));
		CheckServerFixturesIfRequested();
	}
	catch (const std::exception&) {
		return 1;
	}
	return 0;
}
