#pragma once

#include "Contract.h"

#include <cstddef>
#include <cstdint>
#include <functional>
#include <optional>
#include <string>
#include <string_view>
#include <utility>

namespace dql::companion
{
	inline constexpr std::string_view kPapyrusClass = "AIAgentFunctions";
	inline constexpr std::string_view kPapyrusFunction = "sendMessageToActor";
	inline constexpr std::string_view kPapyrusMessageType = "inputtext";

	struct ExpectedConfigIds
	{
		std::optional<std::string> active;
		std::optional<std::string> pending;

		bool operator==(const ExpectedConfigIds&) const = default;
	};

	struct TargetIdentity
	{
		std::string formId;
		std::string name;

		bool operator==(const TargetIdentity&) const = default;
	};

	struct StatusProjection
	{
		std::string operationId;
		std::string state;
		ExpectedConfigIds expected;
		std::optional<TargetIdentity> target;
		std::optional<std::int64_t> expiresAt;
	};

	struct DispatchGuard
	{
		bool requestCurrent{};
		bool actorKeyMapped{};
		bool actorAlive{};
		std::string_view expectedName;
		std::uint32_t expectedFormId{};
		std::string_view currentName;
		std::uint32_t currentFormId{};
	};

	enum class DispatchResult
	{
		notAttempted,
		queueAccepted,
		queueNotConfirmed
	};

	[[nodiscard]] bool IsValidOpaqueToken(std::string_view token) noexcept;
	[[nodiscard]] bool IsFormId(std::string_view formId) noexcept;
	[[nodiscard]] bool NamesMatchManagedActor(std::string_view rosterName, std::string_view currentName) noexcept;

	class FreshActorNameMatch
	{
	public:
		FreshActorNameMatch(std::string_view expectedName, const void* mappedActor) noexcept :
			expectedName_(expectedName), mappedActor_(mappedActor) {}

		[[nodiscard]] bool Observe(const void* actor, std::string_view actualName);
		[[nodiscard]] bool IsUniqueMappedActor() const noexcept;

	private:
		std::string_view expectedName_;
		const void* mappedActor_{};
		const void* matchingMappedActor_{};
		std::size_t matchCount_{};
	};

	[[nodiscard]] bool CanUseStockDestination(std::string_view startupIni, std::string_view currentIni,
		std::string_view facadeBase);
	[[nodiscard]] std::string FormIdHex(std::uint32_t formId);
	[[nodiscard]] bool IsKnownCompanionState(std::string_view state) noexcept;
	[[nodiscard]] bool IsValidStageData(const dql::contract::Json& data, std::string_view operationId);
	[[nodiscard]] bool IsFreshStageData(const dql::contract::Json& data, std::string_view operationId,
		std::int64_t nowUnixSeconds);
	[[nodiscard]] std::optional<StatusProjection> ProjectStatus(
		const dql::contract::Json& data, std::string_view operationId,
		const std::optional<ExpectedConfigIds>& originalExpected,
		const std::optional<TargetIdentity>& originalTarget = std::nullopt);

	template <class Actor, class Dispatcher>
	[[nodiscard]] DispatchResult DispatchStockPlayerInput(std::string_view token,
		const DispatchGuard& guard, Actor&& actor, Dispatcher&& dispatcher)
	{
		if (!IsValidOpaqueToken(token) || !guard.requestCurrent || !guard.actorKeyMapped || !guard.actorAlive ||
			guard.expectedName.empty() || !NamesMatchManagedActor(guard.expectedName, guard.currentName) ||
			guard.expectedFormId == 0 || guard.currentFormId != guard.expectedFormId) {
			return DispatchResult::notAttempted;
		}
		const bool queued = std::invoke(std::forward<Dispatcher>(dispatcher),
			kPapyrusClass, kPapyrusFunction, token, kPapyrusMessageType, std::forward<Actor>(actor));
		return queued ? DispatchResult::queueAccepted : DispatchResult::queueNotConfirmed;
	}
}
