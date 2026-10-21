#include "PcvCompanionPolicy.h"

#include "NativePolicy.h"

#include <algorithm>
#include <array>
#include <charconv>
#include <cctype>
#include <cstdint>
#include <limits>
#include <string>

namespace dql::companion
{
	namespace
	{
		struct Destination
		{
		std::string host;
		std::uint16_t port{};
		std::string basePath;
		};

		std::string_view Trim(std::string_view value)
		{
			while (!value.empty() && std::isspace(static_cast<unsigned char>(value.front()))) value.remove_prefix(1);
			while (!value.empty() && std::isspace(static_cast<unsigned char>(value.back()))) value.remove_suffix(1);
			return value;
		}

		bool ValidPath(std::string_view path)
		{
			if (path.empty()) return true;
			if (path.front() != '/' || path.find("//") != std::string_view::npos ||
				path.find_first_of("\\%?#") != std::string_view::npos) return false;
			return std::all_of(path.begin(), path.end(), [](unsigned char ch) {
				return (ch >= 'A' && ch <= 'Z') || (ch >= 'a' && ch <= 'z') ||
					(ch >= '0' && ch <= '9') || ch == '/' || ch == '-' || ch == '_' || ch == '.' || ch == '~';
			});
		}

		std::optional<std::uint16_t> ParsePort(std::string_view value)
		{
			unsigned port{};
			const auto parsed = std::from_chars(value.data(), value.data() + value.size(), port);
			if (value.empty() || parsed.ec != std::errc{} || parsed.ptr != value.data() + value.size() ||
				port == 0 || port > 65535) return std::nullopt;
			return static_cast<std::uint16_t>(port);
		}

		void LowerAscii(std::string& value)
		{
			for (auto& ch : value) {
				if (ch >= 'A' && ch <= 'Z') ch = static_cast<char>(ch - 'A' + 'a');
			}
		}

		std::optional<Destination> ParseFacadeBase(std::string_view value)
		{
			if (value.empty() || value.size() > 2048 || value.find_first_of("\\%?#@") != std::string_view::npos) return {};
			const auto separator = value.find("://");
			if (separator == std::string_view::npos) return {};
			std::string scheme(value.substr(0, separator));
			LowerAscii(scheme);
			if (scheme != "http") return {};

			const auto authorityStart = separator + 3;
			const auto pathStart = value.find('/', authorityStart);
			const auto authority = value.substr(authorityStart,
				pathStart == std::string_view::npos ? value.size() - authorityStart : pathStart - authorityStart);
			if (authority.empty()) return {};
			const auto colon = authority.find(':');
			if (colon != std::string_view::npos && authority.find(':', colon + 1) != std::string_view::npos) return {};
			std::string host(authority.substr(0, colon));
			if (host.empty() || !std::all_of(host.begin(), host.end(), [](unsigned char ch) {
				return (ch >= 'A' && ch <= 'Z') || (ch >= 'a' && ch <= 'z') ||
					(ch >= '0' && ch <= '9') || ch == '.' || ch == '-';
			})) return {};
			LowerAscii(host);

			const auto port = colon == std::string_view::npos ? std::optional<std::uint16_t>{ 80 } :
				ParsePort(authority.substr(colon + 1));
			if (!port) return {};
			std::string path = pathStart == std::string_view::npos ? std::string{} : std::string(value.substr(pathStart));
			while (!path.empty() && path.back() == '/') path.pop_back();
			if (!ValidPath(path)) return {};
			return Destination{ std::move(host), *port, std::move(path) };
		}

		std::optional<Destination> ParseExplicitStockIni(std::string_view contents)
		{
			if (contents.empty() || contents.size() > 16 * 1024 || contents.find('\0') != std::string_view::npos) return {};
			std::string host;
			std::string portText;
			std::string path = "/HerikaServer/comm.php";
			while (!contents.empty()) {
				auto end = contents.find('\n');
				auto line = contents.substr(0, end);
				contents = end == std::string_view::npos ? std::string_view{} : contents.substr(end + 1);
				if (!line.empty() && line.back() == '\r') line.remove_suffix(1);
				if (line.empty() || line.front() == '#') continue;
				const auto delimiter = line.find('=');
				if (delimiter == std::string_view::npos) return {};
				const auto key = line.substr(0, delimiter);
				const auto value = Trim(line.substr(delimiter + 1));
				if (key == "SERVER") host.assign(value);
				else if (key == "PORT") portText.assign(value);
				else if (key == "PATH") path.assign(value);
			}
			const auto port = ParsePort(portText);
			if (host.empty() || !port || path.empty() || !ValidPath(path)) return {};
			if (!std::all_of(host.begin(), host.end(), [](unsigned char ch) {
				return (ch >= 'A' && ch <= 'Z') || (ch >= 'a' && ch <= 'z') ||
					(ch >= '0' && ch <= '9') || ch == '.' || ch == '-';
			})) return {};
			const auto comm = path.rfind("/comm.php");
			if (comm == std::string::npos || comm + 9 != path.size()) return {};
			std::string root = path.substr(0, comm);
			const auto lastSlash = root.find_last_of('/');
			if (root.substr(lastSlash == std::string::npos ? 0 : lastSlash + 1) != "HerikaServer") return {};
			LowerAscii(host);
			return Destination{ std::move(host), *port, std::move(root) };
		}

		bool HasExactProperties(const dql::contract::Json& value,
			std::initializer_list<std::string_view> keys)
		{
			if (!value.is_object() || value.size() != keys.size()) return false;
			return std::all_of(keys.begin(), keys.end(), [&value](std::string_view key) {
				return value.contains(key);
			});
		}

		std::optional<std::string> ReadNullableUuid(const dql::contract::Json& value)
		{
			if (value.is_null()) return std::nullopt;
			if (!value.is_string() || !dql::policy::IsUuid(value.get_ref<const std::string&>())) return {};
			return value.get<std::string>();
		}

		std::optional<std::int64_t> ReadPositiveInteger(const dql::contract::Json& value)
		{
			if (value.is_number_unsigned()) {
				const auto number = value.get<std::uint64_t>();
				if (number > 0 && number <= static_cast<std::uint64_t>(std::numeric_limits<std::int64_t>::max())) {
					return static_cast<std::int64_t>(number);
				}
				return std::nullopt;
			}
			if (value.is_number_integer()) {
				const auto number = value.get<std::int64_t>();
				if (number > 0) return number;
			}
			return std::nullopt;
		}

		bool IsValidTarget(const dql::contract::Json& target)
		{
			if (target.is_null()) return true;
			if (!HasExactProperties(target, { "formId", "name" })) return false;
			if (!target["formId"].is_string() || !target["name"].is_string()) return false;
			const auto& formId = target["formId"].get_ref<const std::string&>();
			const auto& name = target["name"].get_ref<const std::string&>();
			const auto boundedName = dql::policy::CapUtf8(name, 128);
			return IsFormId(formId) && !name.empty() && boundedName && boundedName->size() == name.size() &&
				std::none_of(name.begin(), name.end(), [](unsigned char ch) { return ch < 0x20 || ch == 0x7F; });
		}
	}

	bool IsValidOpaqueToken(std::string_view token) noexcept
	{
		if (token.size() != 48 || !token.starts_with("pcv1_")) return false;
		return std::all_of(token.begin() + 5, token.end(), [](unsigned char ch) {
			return (ch >= 'A' && ch <= 'Z') || (ch >= 'a' && ch <= 'z') ||
				(ch >= '0' && ch <= '9') || ch == '_' || ch == '-';
		});
	}

	bool IsFormId(std::string_view formId) noexcept
	{
		return formId.size() == 8 && std::all_of(formId.begin(), formId.end(), [](unsigned char ch) {
			return (ch >= '0' && ch <= '9') || (ch >= 'A' && ch <= 'F') || (ch >= 'a' && ch <= 'f');
		});
	}

	bool NamesMatchManagedActor(std::string_view rosterName, std::string_view currentName) noexcept
	{
		return dql::policy::ManagedActorNamesEqual(rosterName, currentName);
	}

	bool FreshActorNameMatch::Observe(const void* actor, std::string_view actualName)
	{
		if (!actor || !NamesMatchManagedActor(expectedName_, actualName)) return false;
		++matchCount_;
		if (actor == mappedActor_) matchingMappedActor_ = actor;
		return true;
	}

	bool FreshActorNameMatch::IsUniqueMappedActor() const noexcept
	{
		return mappedActor_ && matchCount_ == 1 && matchingMappedActor_ == mappedActor_;
	}

	bool CanUseStockDestination(std::string_view startupIni, std::string_view currentIni,
		std::string_view facadeBase)
	{
		if (startupIni.empty() || currentIni != startupIni) return false;
		const auto stock = ParseExplicitStockIni(startupIni);
		const auto facade = ParseFacadeBase(facadeBase);
		return stock && facade && stock->host == facade->host && stock->port == facade->port &&
			stock->basePath == facade->basePath;
	}

	std::string FormIdHex(std::uint32_t formId)
	{
		static constexpr char digits[] = "0123456789abcdef";
		std::string result(8, '0');
		for (std::size_t i = 0; i < result.size(); ++i) {
			result[result.size() - 1 - i] = digits[formId & 0x0FU];
			formId >>= 4;
		}
		return result;
	}

	bool IsKnownCompanionState(std::string_view state) noexcept
	{
		static constexpr std::array states{ "prepared", "consumed", "rejected", "cancelled", "expired", "unknown" };
		return std::find(states.begin(), states.end(), state) != states.end();
	}

	bool IsValidStageData(const dql::contract::Json& data, std::string_view operationId)
	{
		return HasExactProperties(data, { "operationId", "state", "token", "expiresAt" }) &&
			data["operationId"].is_string() && data["operationId"].get_ref<const std::string&>() == operationId &&
			data["state"] == "prepared" && data["token"].is_string() &&
			IsValidOpaqueToken(data["token"].get_ref<const std::string&>()) && ReadPositiveInteger(data["expiresAt"]);
	}

	bool IsFreshStageData(const dql::contract::Json& data, std::string_view operationId,
		std::int64_t nowUnixSeconds)
	{
		if (nowUnixSeconds <= 0 || !IsValidStageData(data, operationId)) return false;
		const auto expiresAt = ReadPositiveInteger(data["expiresAt"]);
		return expiresAt && *expiresAt > nowUnixSeconds;
	}

	std::optional<StatusProjection> ProjectStatus(const dql::contract::Json& data, std::string_view operationId,
		const std::optional<ExpectedConfigIds>& originalExpected,
		const std::optional<TargetIdentity>& originalTarget)
	{
		if (!HasExactProperties(data, { "operationId", "state", "expected", "target", "expiresAt" }) ||
			!data["operationId"].is_string() || data["operationId"].get_ref<const std::string&>() != operationId ||
			!data["state"].is_string() || !IsKnownCompanionState(data["state"].get_ref<const std::string&>()) ||
			!HasExactProperties(data["expected"], { "activeConfigId", "pendingConfigId" })) return std::nullopt;

		const auto active = ReadNullableUuid(data["expected"]["activeConfigId"]);
		const auto pending = ReadNullableUuid(data["expected"]["pendingConfigId"]);
		if ((!active && !data["expected"]["activeConfigId"].is_null()) ||
			(!pending && !data["expected"]["pendingConfigId"].is_null())) return std::nullopt;

		const auto& state = data["state"].get_ref<const std::string&>();
		ExpectedConfigIds serverExpected{ active, pending };
		if (state == "unknown") {
			if (active || pending || !data["target"].is_null() || !data["expiresAt"].is_null()) return std::nullopt;
			return StatusProjection{ std::string(operationId), state, {}, std::nullopt, std::nullopt };
		}
		if (!IsValidTarget(data["target"]) || data["target"].is_null()) return std::nullopt;
		const auto expiresAt = ReadPositiveInteger(data["expiresAt"]);
		if (!expiresAt) return std::nullopt;
		const TargetIdentity serverTarget{
			data["target"]["formId"].get<std::string>(), data["target"]["name"].get<std::string>()
		};
		if ((originalExpected && serverExpected != *originalExpected) ||
			(originalTarget && serverTarget != *originalTarget)) return std::nullopt;

		return StatusProjection{
			std::string(operationId), state, originalExpected.value_or(std::move(serverExpected)),
			originalTarget.value_or(std::move(serverTarget)), expiresAt
		};
	}
}
