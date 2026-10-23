#pragma once

#include <cstddef>
#include <optional>
#include <string>
#include <string_view>

#include <nlohmann/json.hpp>

namespace dql::contract
{
	using Json = nlohmann::json;

	inline constexpr std::size_t kMaxEnvelopeBytes = 65'536;
	inline constexpr std::size_t kMaxDepth = 8;
	inline constexpr std::size_t kMaxObjectProperties = 64;
	inline constexpr std::size_t kMaxArrayItems = 128;
	inline constexpr std::size_t kMaxStringBytes = 8'192;

	struct Envelope
	{
		std::string requestId;
		std::string operation;
		std::string viewToken;
		std::string nativeGeneration;
		std::optional<std::string> serverContext;
		Json payload;
	};

	struct ParseResult
	{
		std::optional<Envelope> envelope;
		std::string errorCode;
	};

	struct JsonParseResult
	{
		std::optional<Json> value;
		std::string errorCode;
	};

	[[nodiscard]] ParseResult ParseRequest(std::string_view raw);
	[[nodiscard]] JsonParseResult ParseBoundedJson(std::string_view raw);
	[[nodiscard]] JsonParseResult ParseServerResponse(std::string_view raw, const Envelope& request);
	[[nodiscard]] Json MakeResponse(const Envelope& request, const Json& data);
	[[nodiscard]] Json MakeError(const Envelope& request, std::string_view code, std::string_view message,
		std::optional<bool> dispatched = std::nullopt);
	[[nodiscard]] Json MakePreDispatchError(const Envelope& request, std::string_view code, std::string_view message);
}
