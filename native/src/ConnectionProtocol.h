#pragma once

#include "Contract.h"

#include <optional>
#include <string>
#include <string_view>

namespace dql::connection
{
	enum class Action
	{
		begin,
		status
	};

	struct ClaimResponse
	{
		std::string state;
		bool approvalPathVerified{};
	};

	[[nodiscard]] dql::contract::Json MakeBeginRequest(std::string_view claimId, std::string_view tokenHash);
	[[nodiscard]] dql::contract::Json MakeStatusRequest(std::string_view claimId);
	[[nodiscard]] std::optional<ClaimResponse> ParseClaimResponse(const dql::contract::Json& response,
		Action action, std::string_view expectedClaimId);
}
