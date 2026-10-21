#include "NativePolicy.h"

#include <algorithm>
#include <array>
#include <cctype>
#include <unordered_map>

namespace dql::policy
{
	namespace
	{
		bool IsContinuation(unsigned char value) noexcept
		{
			return (value & 0xC0U) == 0x80U;
		}

		unsigned char FoldAsciiByte(unsigned char value) noexcept
		{
			return value >= 'A' && value <= 'Z' ? static_cast<unsigned char>(value - 'A' + 'a') : value;
		}

		std::string FoldAscii(std::string_view value)
		{
			std::string result(value);
			for (auto& ch : result) {
				ch = static_cast<char>(FoldAsciiByte(static_cast<unsigned char>(ch)));
			}
			return result;
		}

		std::string_view TrimAscii(std::string_view value) noexcept
		{
			while (!value.empty() && (value.front() == ' ' || value.front() == '\t' || value.front() == '\r' || value.front() == '\n')) {
				value.remove_prefix(1);
			}
			while (!value.empty() && (value.back() == ' ' || value.back() == '\t' || value.back() == '\r' || value.back() == '\n')) {
				value.remove_suffix(1);
			}
			return value;
		}

		bool IsPluginName(std::string_view value) noexcept
		{
			const auto dot = value.find_last_of('.');
			if (dot == std::string_view::npos || dot == 0 || dot > 64) {
				return false;
			}
			const auto isAsciiAlnum = [](unsigned char ch) {
				return (ch >= 'A' && ch <= 'Z') || (ch >= 'a' && ch <= 'z') || (ch >= '0' && ch <= '9');
			};
			const auto baseName = value.substr(0, dot);
			if (!isAsciiAlnum(static_cast<unsigned char>(baseName.front())) ||
				!std::all_of(baseName.begin() + 1, baseName.end(), [&](unsigned char ch) {
					return isAsciiAlnum(ch) || ch == ' ' || ch == '_' || ch == '(' || ch == ')' || ch == '.' || ch == '-';
				})) {
				return false;
			}
			std::string extension(value.substr(dot));
			std::transform(extension.begin(), extension.end(), extension.begin(), [](unsigned char ch) {
				return static_cast<char>(std::tolower(ch));
			});
			return extension == ".esm" || extension == ".esp" || extension == ".esl";
		}
	}

	std::optional<std::uint32_t> NormalizeInputMacro(InputSource source, std::uint32_t normalizedCode) noexcept
	{
		switch (source) {
		case InputSource::keyboard:
			return normalizedCode < 256 ? std::optional(normalizedCode) : std::nullopt;
		case InputSource::mouse:
			return std::nullopt;
		case InputSource::gamepad:
			return normalizedCode >= 266 && normalizedCode < 282 ? std::optional(normalizedCode) : std::nullopt;
		}
		return std::nullopt;
	}

	bool ShouldToggleOpenHotkey(bool ownViewFocused, bool assignedKeyMatches, InputSource source) noexcept
	{
		return !ownViewFocused && assignedKeyMatches &&
			(source == InputSource::keyboard || source == InputSource::gamepad);
	}

	bool ShouldNotifyUiBeforeNativeClose(bool closeAlreadyHandledByUi, bool ownViewFocused) noexcept
	{
		return !closeAlreadyHandledByUi && ownViewFocused;
	}

	bool CanOpenView(bool uiAvailable, bool anotherPrismaViewFocused, bool gamePaused,
		bool modalMenuOpen, bool consoleOpen, bool dialogueOpen, bool loadingOrMainMenuOpen) noexcept
	{
		return uiAvailable && !anotherPrismaViewFocused && !gamePaused && !modalMenuOpen &&
			!consoleOpen && !dialogueOpen && !loadingOrMainMenuOpen;
	}

	std::optional<std::string> CapUtf8(std::string_view value, std::size_t maxBytes)
	{
		std::size_t prefixBytes = 0;
		for (std::size_t i = 0; i < value.size();) {
			const auto lead = static_cast<unsigned char>(value[i]);
			std::size_t width = 0;
			if (lead <= 0x7F) {
				width = 1;
			} else if (lead >= 0xC2 && lead <= 0xDF) {
				width = 2;
			} else if (lead >= 0xE0 && lead <= 0xEF) {
				width = 3;
			} else if (lead >= 0xF0 && lead <= 0xF4) {
				width = 4;
			} else {
				return std::nullopt;
			}
			if (value.size() - i < width) {
				return std::nullopt;
			}
			for (std::size_t j = 1; j < width; ++j) {
				if (!IsContinuation(static_cast<unsigned char>(value[i + j]))) {
					return std::nullopt;
				}
			}
			if (width == 3) {
				const auto second = static_cast<unsigned char>(value[i + 1]);
				if ((lead == 0xE0 && second < 0xA0) || (lead == 0xED && second > 0x9F)) {
					return std::nullopt;
				}
			}
			if (width == 4) {
				const auto second = static_cast<unsigned char>(value[i + 1]);
				if ((lead == 0xF0 && second < 0x90) || (lead == 0xF4 && second > 0x8F)) {
					return std::nullopt;
				}
			}
			if (prefixBytes == i && i + width <= maxBytes) {
				prefixBytes += width;
			}
			i += width;
		}
		return std::string(value.substr(0, prefixBytes));
	}

	ManagedNameSet CollectManagedNames(std::span<const std::string_view> names, std::size_t maxNames)
	{
		struct Entry
		{
			std::string name;
			bool duplicate{};
		};
		std::vector<Entry> entries;
		std::unordered_map<std::string, std::size_t> indexes;
		ManagedNameSet result;
		for (const auto raw : names) {
			const auto trimmed = TrimAscii(raw);
			if (trimmed.empty()) {
				continue;
			}
			const auto capped = CapUtf8(trimmed, 128);
			if (!capped || capped->empty() || capped->size() != trimmed.size()) {
				result.limited = true;
				continue;
			}
			if (std::any_of(capped->begin(), capped->end(), [](unsigned char ch) { return ch < 0x20 || ch == 0x7F; })) {
				result.limited = true;
				continue;
			}
			const auto folded = ManagedActorNameKey(*capped);
			const auto found = indexes.find(folded);
			if (found != indexes.end()) {
				entries[found->second].duplicate = true;
				continue;
			}
			indexes.emplace(folded, entries.size());
			entries.push_back({ *capped, false });
		}
		for (const auto& entry : entries) {
			auto& destination = entry.duplicate ? result.ambiguousNames : result.uniqueNames;
			if (destination.size() >= maxNames) {
				result.limited = true;
				continue;
			}
			destination.push_back(entry.name);
		}
		return result;
	}

	std::string ManagedActorNameKey(std::string_view value)
	{
		return FoldAscii(TrimAscii(value));
	}

	bool ManagedActorNamesEqual(std::string_view left, std::string_view right) noexcept
	{
		left = TrimAscii(left);
		right = TrimAscii(right);
		if (left.empty() || right.empty() || left.size() != right.size()) return false;
		for (std::size_t i = 0; i < left.size(); ++i) {
			if (FoldAsciiByte(static_cast<unsigned char>(left[i])) != FoldAsciiByte(static_cast<unsigned char>(right[i]))) {
				return false;
			}
		}
		return true;
	}

	std::optional<std::size_t> FindManagedActorIndex(
		std::span<const std::string_view> names, std::string_view targetName) noexcept
	{
		std::optional<std::size_t> match;
		for (std::size_t i = 0; i < names.size(); ++i) {
			if (!ManagedActorNamesEqual(names[i], targetName)) continue;
			if (match) return std::nullopt;
			match = i;
		}
		return match;
	}

	bool IsAllowedPlayerText(std::string_view text) noexcept
	{
		if (text.empty() || text.size() > 8'000 || text.find('\0') != std::string_view::npos) {
			return false;
		}
		std::size_t characters = 0;
		for (std::size_t i = 0; i < text.size(); ++characters) {
			const auto lead = static_cast<unsigned char>(text[i]);
			std::size_t width = 0;
			if (lead <= 0x7F) {
				width = 1;
			} else if (lead >= 0xC2 && lead <= 0xDF) {
				width = 2;
			} else if (lead >= 0xE0 && lead <= 0xEF) {
				width = 3;
			} else if (lead >= 0xF0 && lead <= 0xF4) {
				width = 4;
			} else {
				return false;
			}
			if (text.size() - i < width) {
				return false;
			}
			for (std::size_t j = 1; j < width; ++j) {
				if (!IsContinuation(static_cast<unsigned char>(text[i + j]))) {
					return false;
				}
			}
			if (width == 3) {
				const auto second = static_cast<unsigned char>(text[i + 1]);
				if ((lead == 0xE0 && second < 0xA0) || (lead == 0xED && second > 0x9F)) {
					return false;
				}
			}
			if (width == 4) {
				const auto second = static_cast<unsigned char>(text[i + 1]);
				if ((lead == 0xF0 && second < 0x90) || (lead == 0xF4 && second > 0x8F)) {
					return false;
				}
			}
			i += width;
		}
		return characters <= 2'000;
	}

	bool IsUuid(std::string_view value) noexcept
	{
		if (value.size() != 36) {
			return false;
		}
		for (std::size_t i = 0; i < value.size(); ++i) {
			if (i == 8 || i == 13 || i == 18 || i == 23) {
				if (value[i] != '-') return false;
			} else if (!std::isxdigit(static_cast<unsigned char>(value[i]))) {
				return false;
			}
		}
		return true;
	}

	bool IsGeneration(std::string_view value) noexcept
	{
		return !value.empty() && value.size() <= 20 &&
			std::all_of(value.begin(), value.end(), [](unsigned char ch) { return ch >= '0' && ch <= '9'; });
	}

	bool IsSafeHeaderValue(std::string_view value) noexcept
	{
		return std::all_of(value.begin(), value.end(), [](unsigned char ch) { return ch >= 0x20 && ch <= 0x7E; });
	}

	std::optional<std::string> CanonicalBaseFormId(std::string_view pluginName, std::uint32_t localFormId, bool dynamicForm)
	{
		if (dynamicForm || !IsPluginName(pluginName)) {
			return std::nullopt;
		}
		static constexpr char kHex[] = "0123456789ABCDEF";
		std::array<char, 8> hex{};
		for (std::size_t i = 0; i < hex.size(); ++i) {
			hex[hex.size() - 1 - i] = kHex[(localFormId >> (i * 4)) & 0x0FU];
		}
		std::string result(pluginName);
		result.push_back('|');
		result.append(hex.data(), hex.size());
		return result;
	}

	std::optional<NavigationCommand> MapGamepadButton(std::uint32_t keyCode) noexcept
	{
		switch (keyCode) {
		case 266: return NavigationCommand::up;
		case 267: return NavigationCommand::down;
		case 268: return NavigationCommand::left;
		case 269: return NavigationCommand::right;
		case 271: return NavigationCommand::back;
		case 274: return NavigationCommand::previous;
		case 275: return NavigationCommand::next;
		case 276: return NavigationCommand::activate;
		case 277: return NavigationCommand::back;
		default: return std::nullopt;
		}
	}

	InstanceMetadata ClassifyExtraData(std::span<const std::uint32_t> typeCodes) noexcept
	{
		if (typeCodes.empty()) {
			return InstanceMetadata::none;
		}
		for (const auto typeCode : typeCodes) {
			if (typeCode != 0x24) {  // Skyrim ExtraCount is the only instance metadata used as a plain stack count.
				return InstanceMetadata::unsafe;
			}
		}
		return InstanceMetadata::countOnly;
	}

	bool IsTransferableStack(const InventoryTraits& traits) noexcept
	{
		return traits.count > 0 && traits.stableForm && traits.inventoryObject && !traits.dynamicForm &&
			traits.instanceMetadata != InstanceMetadata::unsafe && !traits.questObject && !traits.equipped && !traits.enchanted &&
			!traits.favorited && !traits.poisoned && !traits.leveled;
	}

	bool ValidateTransferLines(std::span<const TransferLine> lines, std::int64_t goldAmount,
		std::int64_t availableGold) noexcept
	{
		if (goldAmount < 0 || availableGold < 0 || goldAmount > availableGold ||
			lines.size() + (goldAmount > 0 ? 1U : 0U) > 8U) {
			return false;
		}
		for (std::size_t i = 0; i < lines.size(); ++i) {
			const auto& line = lines[i];
			if (!IsUuid(line.itemKey) || line.requestedQuantity <= 0 || line.availableQuantity < 0 ||
				line.requestedQuantity > line.availableQuantity) {
				return false;
			}
			for (std::size_t j = 0; j < i; ++j) {
				if (lines[j].itemKey == line.itemKey) {
					return false;
				}
			}
		}
		return true;
	}

	bool CanUseCorrespondence(const NativeAccessState& access, std::string_view requestServerContext) noexcept
	{
		return !access.serverContext.empty() && requestServerContext == access.serverContext &&
			access.authenticated && access.compose && access.correspondenceReady;
	}

	bool CanSendCompanionPcv(const NativeAccessState& access, std::string_view requestServerContext) noexcept
	{
		return !access.serverContext.empty() && requestServerContext == access.serverContext &&
			access.authenticated && access.pcv && access.pcvReady;
	}
}
