#pragma once

#include <cstdint>
#include <optional>
#include <span>
#include <string>
#include <string_view>
#include <vector>

namespace dql::policy
{
	enum class InputSource
	{
		keyboard,
		mouse,
		gamepad
	};

	enum class InstanceMetadata
	{
		none,
		countOnly,
		unsafe
	};

	enum class NavigationCommand
	{
	up,
	down,
	left,
	right,
	next,
	previous,
	activate,
	back
	};

	struct InventoryTraits
	{
		std::int32_t count{};
		bool stableForm{};
		bool inventoryObject{};
		bool dynamicForm{};
		InstanceMetadata instanceMetadata{ InstanceMetadata::none };
		bool questObject{};
		bool equipped{};
		bool enchanted{};
		bool favorited{};
		bool poisoned{};
		bool leveled{};
	};

	struct TransferLine
	{
		std::string_view itemKey;
		std::int64_t requestedQuantity{};
		std::int64_t availableQuantity{};
	};

	struct NativeAccessState
	{
		std::string_view serverContext;
		bool authenticated{};
		bool compose{};
		bool correspondenceReady{};
		bool pcv{};
		bool pcvReady{};
	};

	struct ManagedNameSet
	{
		std::vector<std::string> uniqueNames;
		std::vector<std::string> ambiguousNames;
		bool limited{};
	};

	template <class View>
	class ViewInitializeState
	{
	public:
		void Reset() noexcept
		{
			domReadyView_ = View{};
			initializeSent_ = false;
		}

		void ResetSent() noexcept { initializeSent_ = false; }

		void ObserveDomReady(View currentView, View callbackView, bool creatingView) noexcept
		{
			if (callbackView != View{} && (creatingView || (currentView != View{} && currentView == callbackView))) {
				domReadyView_ = callbackView;
			}
		}

		[[nodiscard]] bool IsCurrentDomReady(View currentView) const noexcept
		{
			return currentView != View{} && domReadyView_ == currentView;
		}

		[[nodiscard]] bool TryMarkSent(View currentView, bool viewReady, bool hasToken) noexcept
		{
			if (!IsCurrentDomReady(currentView) || !viewReady || !hasToken || initializeSent_) {
				return false;
			}
			initializeSent_ = true;
			return true;
		}

	private:
		View domReadyView_{};
		bool initializeSent_{};
	};

	[[nodiscard]] inline std::string BuildInitializeInvokeScript(std::string_view payloadJson)
	{
		return "(()=>{const payload=" + std::string(payloadJson) +
			";let accepted=false,status='handler_missing';try{const initialize=window.departmentInitialize;"
			"if(typeof initialize==='function'){accepted=initialize(JSON.stringify(payload))===true;"
			"status=accepted?'accepted':'rejected';}}catch{status='exception';}"
			"return JSON.stringify({viewToken:payload.viewToken,nativeGeneration:payload.nativeGeneration,accepted,status});})()";
	}

	[[nodiscard]] std::optional<NavigationCommand> MapGamepadButton(std::uint32_t keyCode) noexcept;
	[[nodiscard]] std::optional<std::uint32_t> NormalizeInputMacro(InputSource source, std::uint32_t normalizedCode) noexcept;
	[[nodiscard]] bool ShouldToggleOpenHotkey(bool ownViewFocused, bool assignedKeyMatches, InputSource source) noexcept;
	[[nodiscard]] bool ShouldNotifyUiBeforeNativeClose(bool closeAlreadyHandledByUi, bool ownViewFocused) noexcept;
	[[nodiscard]] bool CanOpenView(bool uiAvailable, bool anotherPrismaViewFocused, bool gamePaused,
		bool modalMenuOpen, bool consoleOpen, bool dialogueOpen, bool loadingOrMainMenuOpen) noexcept;
	[[nodiscard]] std::optional<std::string> CapUtf8(std::string_view value, std::size_t maxBytes);
	[[nodiscard]] ManagedNameSet CollectManagedNames(std::span<const std::string_view> names, std::size_t maxNames = 64);
	[[nodiscard]] std::string ManagedActorNameKey(std::string_view value);
	[[nodiscard]] bool ManagedActorNamesEqual(std::string_view left, std::string_view right) noexcept;
	[[nodiscard]] std::optional<std::size_t> FindManagedActorIndex(
		std::span<const std::string_view> names, std::string_view targetName) noexcept;
	[[nodiscard]] bool IsAllowedPlayerText(std::string_view text) noexcept;
	[[nodiscard]] bool IsUuid(std::string_view value) noexcept;
	[[nodiscard]] bool IsGeneration(std::string_view value) noexcept;
	[[nodiscard]] bool IsSafeHeaderValue(std::string_view value) noexcept;
	[[nodiscard]] std::optional<std::string> CanonicalBaseFormId(std::string_view pluginName, std::uint32_t localFormId, bool dynamicForm);
	[[nodiscard]] InstanceMetadata ClassifyExtraData(std::span<const std::uint32_t> typeCodes) noexcept;
	[[nodiscard]] bool IsTransferableStack(const InventoryTraits& traits) noexcept;
	[[nodiscard]] bool ValidateTransferLines(std::span<const TransferLine> lines, std::int64_t goldAmount,
		std::int64_t availableGold) noexcept;
	[[nodiscard]] bool CanUseCorrespondence(const NativeAccessState& access,
		std::string_view requestServerContext) noexcept;
	[[nodiscard]] bool CanSendCompanionPcv(const NativeAccessState& access,
		std::string_view requestServerContext) noexcept;
}
