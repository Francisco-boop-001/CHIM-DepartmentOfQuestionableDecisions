#include "ConnectionProtocol.h"

#include "NativePolicy.h"

#include <algorithm>
#include <initializer_list>

namespace dql::connection
{
	namespace
	{
		bool HasExactProperties(const dql::contract::Json& value,
			std::initializer_list<std::string_view> keys)
		{
			if (!value.is_object() || value.size() != keys.size()) return false;
			return std::all_of(keys.begin(), keys.end(), [&value](std::string_view key) {
				return value.contains(key);
			});
		}
	}

	dql::contract::Json MakeBeginRequest(std::string_view claimId, std::string_view tokenHash)
	{
		return dql::contract::Json{
			{ "action", "begin" }, { "claimId", std::string(claimId) }, { "tokenHash", std::string(tokenHash) }
		};
	}

	dql::contract::Json MakeStatusRequest(std::string_view claimId)
	{
		return dql::contract::Json{ { "action", "status" }, { "claimId", std::string(claimId) } };
	}

	std::optional<ClaimResponse> ParseClaimResponse(const dql::contract::Json& response,
		Action action, std::string_view expectedClaimId)
	{
		if (!response.is_object() || !response.contains("ok") || !response.contains("state") ||
			!response.contains("claimId")) {
			return std::nullopt;
		}
		if (!response["ok"].is_boolean() || !response["ok"].get<bool>() ||
			!response["claimId"].is_string() ||
			response["claimId"].get_ref<const std::string&>() != expectedClaimId ||
			!dql::policy::IsUuid(expectedClaimId) || !response["state"].is_string()) {
			return std::nullopt;
		}
		const auto& state = response["state"].get_ref<const std::string&>();
		if (state != "pending" && state != "approved" && state != "expired") return std::nullopt;

		const bool hasApprovalPath = action == Action::begin && state == "pending";
		if ((hasApprovalPath && !HasExactProperties(response, { "ok", "state", "claimId", "approvalPath" })) ||
			(!hasApprovalPath && !HasExactProperties(response, { "ok", "state", "claimId" }))) {
			return std::nullopt;
		}

		bool approvalPathVerified = false;
		if (action == Action::begin && response.contains("approvalPath")) {
			const auto& approvalPath = response["approvalPath"];
			const std::string expectedPath = "/ext/questionable_decisions/index.php?claim=" + std::string(expectedClaimId);
			if (!approvalPath.is_string() ||
				approvalPath.get_ref<const std::string&>() != expectedPath) {
				return std::nullopt;
			}
			approvalPathVerified = true;
		}
		return ClaimResponse{ state, approvalPathVerified };
	}
}
