#pragma once

#include "Contract.h"

#include <optional>
#include <string>
#include <string_view>

namespace dql::connection
{
	inline constexpr std::string_view kProducerType = "physics_raw";

	enum class Action
	{
		begin,
		status
	};

	struct ClaimResponse
	{
		std::string state;
	};

	struct ClaimEvent
	{
		std::string claimId;
		std::string tokenHash;
		std::string type;
		std::string message;
	};

	[[nodiscard]] dql::contract::Json MakeBeginRequest(std::string_view claimId, std::string_view tokenHash);
	[[nodiscard]] dql::contract::Json MakeStatusRequest(std::string_view claimId);
	[[nodiscard]] std::optional<ClaimEvent> MakeClaimEvent(std::string_view claimId, std::string_view tokenHash);
	[[nodiscard]] std::optional<ClaimResponse> ParseClaimResponse(const dql::contract::Json& response,
		std::string_view expectedClaimId);
}
