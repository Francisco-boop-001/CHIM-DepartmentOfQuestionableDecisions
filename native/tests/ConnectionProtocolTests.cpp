#include "../src/ConnectionProtocol.h"

#include <cstdlib>
#include <fstream>
#include <iterator>
#include <stdexcept>
#include <string>

#define CHECK(condition) do { if (!(condition)) throw std::runtime_error("check failed: " #condition); } while (false)

namespace
{
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
		const auto checkResponse = [&fixture](const char* key, dql::connection::Action action,
			std::string_view expectedState, bool approvalPathVerified) {
			const auto& response = fixture.at(key);
			CHECK(response.is_object() && response.contains("claimId") && response.at("claimId").is_string());
			const auto claimId = response.at("claimId").get<std::string>();
			const auto result = dql::connection::ParseClaimResponse(response, action, claimId);
			CHECK(result && result->state == expectedState && result->approvalPathVerified == approvalPathVerified);
		};
		checkResponse("beginPending", dql::connection::Action::begin, "pending", true);
		checkResponse("beginApproved", dql::connection::Action::begin, "approved", false);
		checkResponse("beginExpired", dql::connection::Action::begin, "expired", false);
		checkResponse("statusApproved", dql::connection::Action::status, "approved", false);
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

		const dql::contract::Json pending{
			{ "ok", true }, { "state", "pending" }, { "claimId", claimId },
			{ "approvalPath", "/ext/questionable_decisions/index.php?claim=" + std::string(claimId) }
		};
		const auto parsedPending = dql::connection::ParseClaimResponse(pending,
			dql::connection::Action::begin, claimId);
		CHECK(parsedPending && parsedPending->state == "pending" && parsedPending->approvalPathVerified);

		const auto pendingWithoutPath = dql::connection::ParseClaimResponse(
			{ { "ok", true }, { "state", "pending" }, { "claimId", claimId } },
			dql::connection::Action::begin, claimId);
		CHECK(!pendingWithoutPath);
		const auto alreadyApproved = dql::connection::ParseClaimResponse(
			{ { "ok", true }, { "state", "approved" }, { "claimId", claimId } },
			dql::connection::Action::begin, claimId);
		CHECK(alreadyApproved && alreadyApproved->state == "approved" && !alreadyApproved->approvalPathVerified);
		const auto approved = dql::connection::ParseClaimResponse(
			{ { "ok", true }, { "state", "approved" }, { "claimId", claimId } },
			dql::connection::Action::status, claimId);
		CHECK(approved && approved->state == "approved" && !approved->approvalPathVerified);
		const auto expiredBegin = dql::connection::ParseClaimResponse(
			{ { "ok", true }, { "state", "expired" }, { "claimId", claimId } },
			dql::connection::Action::begin, claimId);
		CHECK(expiredBegin && expiredBegin->state == "expired" && !expiredBegin->approvalPathVerified);
		const auto expired = dql::connection::ParseClaimResponse(
			{ { "ok", true }, { "state", "expired" }, { "claimId", claimId } },
			dql::connection::Action::status, claimId);
		CHECK(expired && expired->state == "expired");

		CHECK(!dql::connection::ParseClaimResponse(
			{ { "ok", true }, { "state", "pending" }, { "claimId", "223e4567-e89b-12d3-a456-426614174000" } },
			dql::connection::Action::status, claimId));
		CHECK(!dql::connection::ParseClaimResponse(
			{ { "ok", true }, { "state", "pending" }, { "claimId", claimId },
				{ "approvalPath", "https://evil.example/" } }, dql::connection::Action::begin, claimId));
		CHECK(!dql::connection::ParseClaimResponse(
			{ { "ok", true }, { "state", "approved" }, { "claimId", claimId },
				{ "approvalPath", "/ext/questionable_decisions/index.php?claim=" + claimId } },
			dql::connection::Action::begin, claimId));
		CHECK(!dql::connection::ParseClaimResponse(
			{ { "ok", false }, { "state", "pending" }, { "claimId", claimId } },
			dql::connection::Action::status, claimId));
		CHECK(!dql::connection::ParseClaimResponse(
			{ { "ok", true }, { "state", "waiting" }, { "claimId", claimId } },
			dql::connection::Action::status, claimId));
		CHECK(!dql::connection::ParseClaimResponse(
			{ { "ok", true }, { "state", "pending" }, { "claimId", claimId }, { "extra", "value" } },
			dql::connection::Action::status, claimId));
		CheckServerFixturesIfRequested();
	}
	catch (const std::exception&) {
		return 1;
	}
	return 0;
}
