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

		bool IsLowercaseClaimId(std::string_view value)
		{
			return dql::policy::IsUuid(value) && std::none_of(value.begin(), value.end(), [](char ch) {
				return ch >= 'A' && ch <= 'F';
			});
		}

		bool IsLowercaseHash(std::string_view value)
		{
			return value.size() == 64 && std::all_of(value.begin(), value.end(), [](char ch) {
				return (ch >= '0' && ch <= '9') || (ch >= 'a' && ch <= 'f');
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

	std::optional<ClaimEvent> MakeClaimEvent(std::string_view claimId, std::string_view tokenHash)
	{
		if (!IsLowercaseClaimId(claimId) || !IsLowercaseHash(tokenHash)) return std::nullopt;
		return ClaimEvent{
			.claimId = std::string(claimId),
			.tokenHash = std::string(tokenHash),
			.type = std::string(kProducerType),
			.message = "dql_connect_v1:" + std::string(claimId) + ":" + std::string(tokenHash)
		};
	}

	std::optional<ClaimResponse> ParseClaimResponse(const dql::contract::Json& response,
		std::string_view expectedClaimId)
	{
		if (!HasExactProperties(response, { "ok", "state", "claimId" }) ||
			!IsLowercaseClaimId(expectedClaimId)) {
			return std::nullopt;
		}
		if (!response["ok"].is_boolean() || !response["ok"].get<bool>() ||
			!response["claimId"].is_string() ||
			response["claimId"].get_ref<const std::string&>() != expectedClaimId ||
			!IsLowercaseClaimId(response["claimId"].get_ref<const std::string&>()) ||
			!response["state"].is_string()) {
			return std::nullopt;
		}
		const auto& state = response["state"].get_ref<const std::string&>();
		if (state != "pending" && state != "approved" && state != "expired") return std::nullopt;
		return ClaimResponse{ state };
	}
}
