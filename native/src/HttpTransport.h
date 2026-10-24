#pragma once

#include <cstddef>
#include <cstdint>
#include <functional>
#include <memory>
#include <optional>
#include <string>
#include <string_view>

namespace dql::http
{
	enum class Outcome
	{
		completed,
		unknown,
		rejected
	};

	enum class Route
	{
		departmentApi,
		connection
	};

	enum class ErrorCode
	{
		none,
		invalidRequest,
		invalidBaseUrl,
		invalidBearer,
		queueFull,
		shuttingDown,
		timeout,
		cancelled,
		requestTooLarge,
		responseTooLarge,
		transportFailure
	};

	struct HttpRequest
	{
		std::string requestId;
		std::string viewToken;
		std::string nativeGeneration;
		std::string operation;
		std::string envelopeJson;
		std::wstring serverBase;
		std::wstring trustedHttpBase;
		std::wstring bearerToken;
		bool isWrite{};
		Route route{ Route::departmentApi };
	};

	struct HttpResult
	{
		Outcome outcome{ Outcome::rejected };
		std::uint32_t statusCode{};
		std::string body;
		ErrorCode errorCode{ ErrorCode::none };
	};

	struct SubmitResult
	{
		bool queued{};
		ErrorCode errorCode{ ErrorCode::none };
	};

	[[nodiscard]] std::optional<std::wstring> CanonicalDestinationKey(std::wstring_view base,
		std::wstring_view trustedHttpBase = {});
	// Mirrors CHIM's pinned startup discovery request to its local launcher service.
	[[nodiscard]] std::optional<std::wstring> DiscoverChimServerBase();

	class HttpTransport final
	{
	public:
		using Completion = std::function<void(HttpResult)>;
		static constexpr std::size_t kMaxEnvelopeBytes = 65'536;
		static constexpr std::size_t kMaxResponseBytes = 65'536;

		explicit HttpTransport(std::size_t maxPending = 16);
		~HttpTransport();

		HttpTransport(const HttpTransport&) = delete;
		HttpTransport& operator=(const HttpTransport&) = delete;
		HttpTransport(HttpTransport&&) = delete;
		HttpTransport& operator=(HttpTransport&&) = delete;

		// A rejected Submit has no callback. Accepted requests complete exactly once on the worker thread.
		[[nodiscard]] SubmitResult Submit(HttpRequest request, Completion completion);
		[[nodiscard]] bool HasInFlightWrites() noexcept;
		void CancelView(std::string_view viewToken, std::string_view nativeGeneration,
			bool invalidateGeneration = false) noexcept;
		void Shutdown() noexcept;

	private:
		class Impl;
		std::unique_ptr<Impl> impl_;
	};
}
