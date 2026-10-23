#include "Contract.h"

#include <algorithm>
#include <array>
#include <cstdint>
#include <unordered_set>
#include <utility>
#include <vector>

namespace dql::contract
{
	namespace
	{
		class BoundedSax final : public Json::json_sax_t
		{
		public:
			bool null() override { return Add(Json(nullptr)); }
			bool boolean(bool value) override { return Add(Json(value)); }
			bool number_integer(number_integer_t value) override { return Add(Json(value)); }
			bool number_unsigned(number_unsigned_t value) override { return Add(Json(value)); }
			bool number_float(number_float_t value, const string_t&) override { return Add(Json(value)); }
			bool string(string_t& value) override
			{
				return ValidString(value) && Add(Json(std::move(value)));
			}
			bool binary(binary_t& value) override { return Add(Json(value)); }

			bool start_object(std::size_t) override
			{
				if (frames_.size() >= kMaxDepth) {
					return Fail("too_deep");
				}
				frames_.push_back(Frame{ Json::object(), {}, {} });
				return true;
			}

			bool key(string_t& value) override
			{
				if (frames_.empty() || !frames_.back().value.is_object()) {
					return Fail("malformed_json");
				}
				auto& frame = frames_.back();
				if (!ValidString(value)) {
					return false;
				}
				if (frame.value.size() >= kMaxObjectProperties) {
					return Fail("too_many_properties");
				}
				if (!frame.keys.emplace(value).second) {
					return Fail("duplicate_key");
				}
				frame.pendingKey = std::move(value);
				return true;
			}

			bool end_object() override
			{
				if (frames_.empty() || !frames_.back().value.is_object() || frames_.back().pendingKey.has_value()) {
					return Fail("malformed_json");
				}
				auto value = std::move(frames_.back().value);
				frames_.pop_back();
				return Add(std::move(value));
			}

			bool start_array(std::size_t) override
			{
				if (frames_.size() >= kMaxDepth) {
					return Fail("too_deep");
				}
				frames_.push_back(Frame{ Json::array(), {}, {} });
				return true;
			}

			bool end_array() override
			{
				if (frames_.empty() || !frames_.back().value.is_array()) {
					return Fail("malformed_json");
				}
				auto value = std::move(frames_.back().value);
				frames_.pop_back();
				return Add(std::move(value));
			}

			bool parse_error(std::size_t, const std::string&, const nlohmann::detail::exception&) override
			{
				return Fail("malformed_json");
			}

			[[nodiscard]] bool Complete() const noexcept { return !failed_ && frames_.empty() && root_.has_value(); }
			[[nodiscard]] const Json& Root() const { return *root_; }
			[[nodiscard]] const std::string& ErrorCode() const noexcept { return errorCode_; }

		private:
			struct Frame
			{
				Json value;
				std::optional<std::string> pendingKey;
				std::unordered_set<std::string> keys;
			};

			bool Fail(std::string_view code)
			{
				failed_ = true;
				errorCode_ = code;
				return false;
			}

			bool Add(Json value)
			{
				if (failed_) {
					return false;
				}
				if (frames_.empty()) {
					if (root_.has_value()) {
						return Fail("malformed_json");
					}
					root_ = std::move(value);
					return true;
				}
				auto& frame = frames_.back();
				if (frame.value.is_array()) {
					if (frame.value.size() >= kMaxArrayItems) {
						return Fail("too_many_array_items");
					}
					frame.value.push_back(std::move(value));
					return true;
				}
				if (!frame.value.is_object() || !frame.pendingKey.has_value()) {
					return Fail("malformed_json");
				}
				frame.value.emplace(std::move(*frame.pendingKey), std::move(value));
				frame.pendingKey.reset();
				return true;
			}

			bool ValidString(std::string_view value)
			{
				if (value.size() > kMaxStringBytes) {
					return Fail("string_too_large");
				}
				for (std::size_t i = 0; i < value.size();) {
					const auto lead = static_cast<unsigned char>(value[i]);
					std::size_t width = 0;
					std::uint32_t codepoint = 0;
					if (lead <= 0x7F) {
						width = 1;
						codepoint = lead;
					} else if ((lead & 0xE0) == 0xC0) {
						width = 2;
						codepoint = lead & 0x1F;
					} else if ((lead & 0xF0) == 0xE0) {
						width = 3;
						codepoint = lead & 0x0F;
					} else if ((lead & 0xF8) == 0xF0) {
						width = 4;
						codepoint = lead & 0x07;
					} else {
						return Fail("invalid_utf8");
					}
					if (i + width > value.size()) {
						return Fail("invalid_utf8");
					}
					for (std::size_t j = 1; j < width; ++j) {
						const auto next = static_cast<unsigned char>(value[i + j]);
						if ((next & 0xC0) != 0x80) {
							return Fail("invalid_utf8");
						}
						codepoint = (codepoint << 6) | (next & 0x3F);
					}
					if ((width == 2 && codepoint < 0x80) || (width == 3 && codepoint < 0x800) ||
						(width == 4 && codepoint < 0x10000) || codepoint > 0x10FFFF ||
						(codepoint >= 0xD800 && codepoint <= 0xDFFF)) {
						return Fail("invalid_utf8");
					}
					i += width;
				}
				return true;
			}

			std::vector<Frame> frames_;
			std::optional<Json> root_;
			std::string errorCode_;
			bool failed_{ false };
		};

		bool IsUuid(const Json& value)
		{
			if (!value.is_string() || value.get_ref<const std::string&>().size() != 36) {
				return false;
			}
			const auto& text = value.get_ref<const std::string&>();
			for (std::size_t i = 0; i < text.size(); ++i) {
				if (i == 8 || i == 13 || i == 18 || i == 23) {
					if (text[i] != '-') {
						return false;
					}
				} else if (!((text[i] >= '0' && text[i] <= '9') ||
					(text[i] >= 'a' && text[i] <= 'f') || (text[i] >= 'A' && text[i] <= 'F'))) {
					return false;
				}
			}
			return true;
		}

		bool IsGeneration(const Json& value)
		{
			if (!value.is_string()) {
				return false;
			}
			const auto& text = value.get_ref<const std::string&>();
			if (text.empty() || text.size() > 20) {
				return false;
			}
			return std::all_of(text.begin(), text.end(), [](char c) { return c >= '0' && c <= '9'; });
		}

		bool IsKnownOperation(std::string_view operation)
		{
			constexpr std::array operations{
				"bootstrap", "view.close", "pcv.roster.read", "pcv.status.read", "pcv.arm", "pcv.end", "pcv.send", "pcv.send.status",
				"mp.relationships.read", "sas.issues.read", "correspondence.catalog.read", "correspondence.inventory.read",
				"correspondence.mailbox.read", "correspondence.detail.read", "correspondence.compose", "correspondence.reconcile",
				"connection.begin", "connection.status"
			};
			return std::find(operations.begin(), operations.end(), operation) != operations.end();
		}

		bool HasOnlyKeys(const Json& value, std::initializer_list<std::string_view> keys)
		{
			if (!value.is_object() || value.size() != keys.size()) {
				return false;
			}
			return std::all_of(keys.begin(), keys.end(), [&value](std::string_view key) { return value.contains(key); });
		}

		bool IsCatalogActorKey(const Json& value)
		{
			if (!value.is_string()) {
				return false;
			}
			const auto& key = value.get_ref<const std::string&>();
			if (key == "player") {
				return true;
			}
			if (key.size() < 5 || key.size() > 23 || key.compare(0, 4, "npc:") != 0 || key[4] < '1' || key[4] > '9') {
				return false;
			}
			return std::all_of(key.begin() + 5, key.end(), [](char c) { return c >= '0' && c <= '9'; });
		}

		bool IsCatalogRequestPayload(const Json& payload)
		{
			if (!payload.is_object() || payload.size() > 2) {
				return false;
			}
			for (const auto& [key, _] : payload.items()) {
				if (key != "limit" && key != "cursor") {
					return false;
				}
			}
			if (payload.contains("limit")) {
				const auto& limit = payload["limit"];
				if (!limit.is_number_integer()) {
					return false;
				}
				if (limit.is_number_unsigned()) {
					const auto value = limit.get<std::uint64_t>();
					if (value < 1 || value > 100) return false;
				} else {
					const auto value = limit.get<std::int64_t>();
					if (value < 1 || value > 100) return false;
				}
			}
			if (payload.contains("cursor") && !(payload["cursor"].is_null() ||
				(payload["cursor"].is_string() && payload["cursor"].get_ref<const std::string&>().size() <= 512))) {
				return false;
			}
			return true;
		}

		bool IsCatalogResponseData(const Json& data)
		{
			if (!HasOnlyKeys(data, { "actors", "limited", "revision", "nextCursor" }) ||
				!data["actors"].is_array() || data["actors"].size() > 100 || !data["limited"].is_boolean() ||
				!data["revision"].is_string() || data["revision"].get_ref<const std::string&>().size() != 64 ||
				!(data["nextCursor"].is_null() || (data["nextCursor"].is_string() &&
					data["nextCursor"].get_ref<const std::string&>().size() <= 512))) {
				return false;
			}
			const auto& revision = data["revision"].get_ref<const std::string&>();
			if (!std::all_of(revision.begin(), revision.end(), [](char c) {
					return (c >= '0' && c <= '9') || (c >= 'a' && c <= 'f');
				})) {
				return false;
			}
			for (const auto& actor : data["actors"]) {
				if (!HasOnlyKeys(actor, { "actorKey", "label", "canWrite", "canReceive", "canSignAs" }) ||
					!IsCatalogActorKey(actor["actorKey"]) || !actor["label"].is_string() ||
					actor["label"].get_ref<const std::string&>().size() > 256 ||
					!actor["canWrite"].is_boolean() || !actor["canReceive"].is_boolean() || !actor["canSignAs"].is_boolean()) {
					return false;
				}
			}
			return data.dump(-1, ' ', false).size() <= 48'000;
		}

		JsonParseResult ParseBoundedJsonImpl(std::string_view raw)
		{
			if (raw.empty() || raw.size() > kMaxEnvelopeBytes) {
				return { std::nullopt, "payload_too_large" };
			}
			BoundedSax sax;
			const auto parsed = Json::sax_parse(raw.begin(), raw.end(), &sax, Json::input_format_t::json, true);
			if (!parsed || !sax.Complete()) {
				return { std::nullopt, sax.ErrorCode().empty() ? "malformed_json" : sax.ErrorCode() };
			}
			return { sax.Root(), {} };
		}
	}

	JsonParseResult ParseBoundedJson(std::string_view raw)
	{
		return ParseBoundedJsonImpl(raw);
	}

	ParseResult ParseRequest(std::string_view raw)
	{
		auto parsed = ParseBoundedJson(raw);
		if (!parsed.value) {
			return { std::nullopt, std::move(parsed.errorCode) };
		}
		const auto& root = *parsed.value;
		if (!HasOnlyKeys(root, { "version", "requestId", "operation", "context", "payload" }) ||
			!root["version"].is_number_integer() || root["version"].get<int>() != 1 ||
			!IsUuid(root["requestId"]) || !root["operation"].is_string()) {
			return { std::nullopt, "invalid_request" };
		}
		const auto operation = root["operation"].get<std::string>();
		if (!IsKnownOperation(operation)) {
			return { std::nullopt, "unsupported_operation" };
		}
		const auto& context = root["context"];
		if (!HasOnlyKeys(context, { "viewToken", "nativeGeneration", "serverContext" }) ||
			!IsUuid(context["viewToken"]) || !IsGeneration(context["nativeGeneration"]) ||
			!(context["serverContext"].is_null() || (context["serverContext"].is_string() && context["serverContext"].get_ref<const std::string&>().size() <= 128)) ||
			!root["payload"].is_object()) {
			return { std::nullopt, "invalid_request" };
		}
		const bool isBootstrap = operation == "bootstrap";
		const bool localClose = operation == "view.close";
		const bool isConnectionOperation = operation == "connection.begin" || operation == "connection.status";
		if (((isBootstrap || isConnectionOperation) && !context["serverContext"].is_null()) ||
			(!isBootstrap && !localClose && !isConnectionOperation &&
				(!context["serverContext"].is_string() || context["serverContext"].get_ref<const std::string&>().empty())) ||
			(isConnectionOperation && !root["payload"].empty())) {
			return { std::nullopt, "invalid_request" };
		}
		if (operation == "correspondence.catalog.read" && !IsCatalogRequestPayload(root["payload"])) {
			return { std::nullopt, "invalid_request" };
		}
		return {
			Envelope{
				root["requestId"].get<std::string>(), operation, context["viewToken"].get<std::string>(),
				context["nativeGeneration"].get<std::string>(),
				context["serverContext"].is_null() ? std::nullopt : std::optional(context["serverContext"].get<std::string>()),
				root["payload"]
			},
			{}
		};
	}

	JsonParseResult ParseServerResponse(std::string_view raw, const Envelope& request)
	{
		auto parsed = ParseBoundedJson(raw);
		if (!parsed.value) {
			return parsed;
		}
		const auto& root = *parsed.value;
		const Json expectedContext{
			{ "viewToken", request.viewToken }, { "nativeGeneration", request.nativeGeneration },
			{ "serverContext", request.serverContext ? Json(*request.serverContext) : Json(nullptr) }
		};
		if (!root.is_object() || !root.contains("ok") || !root["ok"].is_boolean() ||
			!root.contains("version") || !root["version"].is_number_integer() || root["version"].get<int>() != 1 ||
			!root.contains("requestId") || root["requestId"] != request.requestId ||
			!root.contains("operation") || root["operation"] != request.operation ||
			!root.contains("context") || root["context"] != expectedContext) {
			return { std::nullopt, "invalid_response" };
		}
		if (root["ok"].get<bool>()) {
			if (!HasOnlyKeys(root, { "version", "requestId", "operation", "context", "ok", "data" }) ||
				!root["data"].is_object()) {
				return { std::nullopt, "invalid_response" };
			}
			if (request.operation == "correspondence.catalog.read" && !IsCatalogResponseData(root["data"])) {
				return { std::nullopt, "invalid_response" };
			}
		} else {
			if (!HasOnlyKeys(root, { "version", "requestId", "operation", "context", "ok", "error" }) ||
				!HasOnlyKeys(root["error"], { "code", "message" }) ||
				!root["error"]["code"].is_string() || root["error"]["code"].get_ref<const std::string&>().size() > 64 ||
				!root["error"]["message"].is_string() || root["error"]["message"].get_ref<const std::string&>().size() > 256) {
				return { std::nullopt, "invalid_response" };
			}
		}
		return parsed;
	}

	Json MakeResponse(const Envelope& request, const Json& data)
	{
		return Json{
			{ "version", 1 }, { "requestId", request.requestId }, { "operation", request.operation },
			{ "context", {{"viewToken", request.viewToken}, {"nativeGeneration", request.nativeGeneration},
				{ "serverContext", request.serverContext ? Json(*request.serverContext) : Json(nullptr) }}},
			{ "ok", true }, { "data", data }
		};
	}

	Json MakeError(const Envelope& request, std::string_view code, std::string_view message, std::optional<bool> dispatched)
	{
		const auto boundedMessage = message.substr(0, 256);
		Json error = {{"code", code}, {"message", boundedMessage}};
		if (dispatched) error["dispatched"] = *dispatched;
		return Json{
			{ "version", 1 }, { "requestId", request.requestId }, { "operation", request.operation },
			{ "context", {{"viewToken", request.viewToken}, {"nativeGeneration", request.nativeGeneration},
				{ "serverContext", request.serverContext ? Json(*request.serverContext) : Json(nullptr) }}},
			{ "ok", false }, { "error", std::move(error) }
		};
	}

	Json MakePreDispatchError(const Envelope& request, std::string_view code, std::string_view message)
	{
		return MakeError(request, code, message, false);
	}
}
