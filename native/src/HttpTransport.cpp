#define WIN32_LEAN_AND_MEAN
#ifndef NOMINMAX
#define NOMINMAX
#endif
#include <windows.h>
#include <winhttp.h>

#include "HttpTransport.h"
#include "NativePolicy.h"

#include <algorithm>
#include <array>
#include <chrono>
#include <condition_variable>
#include <deque>
#include <memory>
#include <mutex>
#include <string_view>
#include <thread>
#include <unordered_map>
#include <utility>
#include <vector>

namespace dql::http
{
	namespace
	{
		constexpr std::wstring_view kFixedPath = L"/ext/questionable_decisions/api.php";
		constexpr std::size_t kMaxPendingRequests = 128;
		constexpr std::size_t kMaxBaseUrlChars = 2048;
		constexpr std::size_t kMaxBearerChars = 512;
		constexpr DWORD kMaxPhaseTimeoutMs = 5000;
		constexpr DWORD kMaxResponseHeaderBytes = 65'536;
		constexpr std::chrono::seconds kTransactionTimeout{ 15 };

		struct ParsedBaseUrl
		{
			std::wstring host;
			std::wstring pathPrefix;
			INTERNET_PORT port{};
			bool secure{};
		};

		class InternetHandle final
		{
		public:
			explicit InternetHandle(HINTERNET handle = nullptr) noexcept : handle_(handle) {}
			~InternetHandle()
			{
				if (handle_) WinHttpCloseHandle(handle_);
			}
			InternetHandle(const InternetHandle&) = delete;
			InternetHandle& operator=(const InternetHandle&) = delete;
			InternetHandle(InternetHandle&& other) noexcept : handle_(std::exchange(other.handle_, nullptr)) {}
			InternetHandle& operator=(InternetHandle&& other) noexcept
			{
				if (this != &other) {
					if (handle_) WinHttpCloseHandle(handle_);
					handle_ = std::exchange(other.handle_, nullptr);
				}
				return *this;
			}
			[[nodiscard]] HINTERNET get() const noexcept { return handle_; }
			[[nodiscard]] explicit operator bool() const noexcept { return handle_ != nullptr; }
			[[nodiscard]] HINTERNET release() noexcept { return std::exchange(handle_, nullptr); }

		private:
			HINTERNET handle_{};
		};

		class WipeWideString final
		{
		public:
			explicit WipeWideString(std::wstring& value) noexcept : value_(value) {}
			~WipeWideString()
			{
				if (!value_.empty()) {
					SecureZeroMemory(value_.data(), value_.size() * sizeof(wchar_t));
					value_.clear();
				}
			}
			WipeWideString(const WipeWideString&) = delete;
			WipeWideString& operator=(const WipeWideString&) = delete;

		private:
			std::wstring& value_;
		};

		class WipeString final
		{
		public:
			explicit WipeString(std::string& value) noexcept : value_(value) {}
			~WipeString()
			{
				if (!value_.empty()) {
					SecureZeroMemory(value_.data(), value_.size());
					value_.clear();
				}
			}
			WipeString(const WipeString&) = delete;
			WipeString& operator=(const WipeString&) = delete;

		private:
			std::string& value_;
		};

		struct AsyncRequestState final
		{
			~AsyncRequestState()
			{
				if (!uploadBody.empty()) SecureZeroMemory(uploadBody.data(), uploadBody.size());
				if (!headers.empty()) SecureZeroMemory(headers.data(), headers.size() * sizeof(wchar_t));
			}

			void Prepare(DWORD expected) noexcept
			{
				std::lock_guard lock(mutex);
				expectedStatus = expected;
				operationComplete = false;
				operationError = ERROR_SUCCESS;
				transferred = 0;
			}

			std::mutex mutex;
			std::condition_variable changed;
			DWORD expectedStatus{};
			bool operationComplete{};
			DWORD operationError{ ERROR_SUCCESS };
			DWORD transferred{};
			bool handleClosing{};
			std::string uploadBody;
			std::wstring headers;
			std::array<char, 8192> readBuffer{};
		};

		struct AsyncStateRegistry final
		{
			std::mutex mutex;
			std::unordered_map<AsyncRequestState*, std::shared_ptr<AsyncRequestState>> states;
		};

		[[nodiscard]] AsyncStateRegistry& GetAsyncStateRegistry()
		{
			// Keep the registry alive until process teardown so late HANDLE_CLOSING callbacks
			// can always find their owned context after a bounded transaction has returned.
			static auto* registry = new AsyncStateRegistry();
			return *registry;
		}

		void RegisterAsyncState(const std::shared_ptr<AsyncRequestState>& state)
		{
			auto& registry = GetAsyncStateRegistry();
			std::lock_guard lock(registry.mutex);
			registry.states.emplace(state.get(), state);
		}

		void UnregisterAsyncState(AsyncRequestState* state) noexcept
		{
			auto& registry = GetAsyncStateRegistry();
			std::lock_guard lock(registry.mutex);
			registry.states.erase(state);
		}

		[[nodiscard]] std::shared_ptr<AsyncRequestState> FindAsyncState(DWORD_PTR context) noexcept
		{
			if (context == 0) return {};
			auto* key = reinterpret_cast<AsyncRequestState*>(context);
			auto& registry = GetAsyncStateRegistry();
			std::lock_guard lock(registry.mutex);
			const auto found = registry.states.find(key);
			return found == registry.states.end() ? std::shared_ptr<AsyncRequestState>{} : found->second;
		}

		void CALLBACK WinHttpStatusCallback(HINTERNET, DWORD_PTR context, DWORD status,
			LPVOID information, DWORD informationLength)
		{
			auto state = FindAsyncState(context);
			if (!state) return;

			bool closing = status == WINHTTP_CALLBACK_STATUS_HANDLE_CLOSING;
			{
				std::lock_guard lock(state->mutex);
				if (closing) {
					state->handleClosing = true;
					if (!state->operationComplete) {
						state->operationError = ERROR_WINHTTP_OPERATION_CANCELLED;
						state->operationComplete = true;
					}
				} else if (status == WINHTTP_CALLBACK_STATUS_REQUEST_ERROR) {
					state->operationError = ERROR_WINHTTP_INTERNAL_ERROR;
					if (information && informationLength >= sizeof(WINHTTP_ASYNC_RESULT)) {
						state->operationError = static_cast<WINHTTP_ASYNC_RESULT*>(information)->dwError;
					}
					state->operationComplete = true;
				} else if (status == state->expectedStatus) {
					if ((status == WINHTTP_CALLBACK_STATUS_WRITE_COMPLETE ||
						status == WINHTTP_CALLBACK_STATUS_DATA_AVAILABLE) && information &&
						informationLength >= sizeof(DWORD)) {
						state->transferred = *static_cast<DWORD*>(information);
					} else if (status == WINHTTP_CALLBACK_STATUS_READ_COMPLETE) {
						state->transferred = informationLength;
					}
					state->operationError = ERROR_SUCCESS;
					state->operationComplete = true;
				}
			}
			state->changed.notify_all();
			if (closing) UnregisterAsyncState(state.get());
		}

		enum class AsyncWaitResult
		{
			completed,
			timedOut,
			cancelled
		};

		[[nodiscard]] AsyncWaitResult WaitForAsyncOperation(AsyncRequestState& state,
			std::chrono::steady_clock::time_point deadline,
			const std::function<bool()>& isCancelled,
			DWORD& operationError, DWORD& transferred)
		{
			std::unique_lock lock(state.mutex);
			for (;;) {
				if (state.operationComplete) {
					operationError = state.operationError;
					transferred = state.transferred;
					return AsyncWaitResult::completed;
				}
				lock.unlock();
				const bool cancelled = isCancelled();
				lock.lock();
				if (state.operationComplete) continue;
				if (cancelled) return AsyncWaitResult::cancelled;
				const auto now = std::chrono::steady_clock::now();
				if (now >= deadline) return AsyncWaitResult::timedOut;
				state.changed.wait_until(lock, std::min(deadline, now + std::chrono::milliseconds(50)));
			}
		}

		void WaitForHandleClosing(AsyncRequestState& state) noexcept
		{
			std::unique_lock lock(state.mutex);
			state.changed.wait(lock, [&state] { return state.handleClosing; });
		}

		[[nodiscard]] bool IsWriteOperation(std::string_view operation) noexcept
		{
			return operation == "pcv.arm" || operation == "pcv.end" || operation == "pcv.companion.stage" ||
				operation == "pcv.companion.cancel" || operation == "correspondence.compose";
		}

		[[nodiscard]] bool IsAllowedOperation(std::string_view operation) noexcept
		{
			static constexpr std::array<std::string_view, 15> allowed{
				"bootstrap",
				"pcv.roster.read",
				"pcv.status.read",
				"pcv.arm",
				"pcv.end",
				"pcv.companion.stage",
				"pcv.companion.status",
				"pcv.companion.cancel",
				"mp.relationships.read",
				"sas.issues.read",
				"correspondence.catalog.read",
				"correspondence.mailbox.read",
				"correspondence.detail.read",
				"correspondence.compose",
				"correspondence.reconcile"
			};
			return std::find(allowed.begin(), allowed.end(), operation) != allowed.end();
		}

		[[nodiscard]] bool IsValidUtf8JsonBytes(std::string_view value) noexcept
		{
			std::size_t i = 0;
			while (i < value.size()) {
				const auto first = static_cast<unsigned char>(value[i]);
				if (first == 0) return false;
				if (first <= 0x7F) {
					++i;
					continue;
				}

				std::size_t count = 0;
				std::uint32_t codepoint = 0;
				if (first >= 0xC2 && first <= 0xDF) {
					count = 2;
					codepoint = first & 0x1FU;
				} else if (first >= 0xE0 && first <= 0xEF) {
					count = 3;
					codepoint = first & 0x0FU;
				} else if (first >= 0xF0 && first <= 0xF4) {
					count = 4;
					codepoint = first & 0x07U;
				} else {
					return false;
				}
				if (i + count > value.size()) return false;
				for (std::size_t offset = 1; offset < count; ++offset) {
					const auto continuation = static_cast<unsigned char>(value[i + offset]);
					if ((continuation & 0xC0U) != 0x80U) return false;
					codepoint = (codepoint << 6U) | (continuation & 0x3FU);
				}
				if ((count == 3 && codepoint < 0x800U) ||
					(count == 4 && codepoint < 0x10000U) ||
					(codepoint >= 0xD800U && codepoint <= 0xDFFFU) || codepoint > 0x10FFFFU) {
					return false;
				}
				i += count;
			}
			return true;
		}

		[[nodiscard]] bool IsValidBearer(std::wstring_view token) noexcept
		{
			if (token.empty() || token.size() > kMaxBearerChars) return false;
			bool padding = false;
			bool sawBaseCharacter = false;
			for (const wchar_t ch : token) {
				const bool base = (ch >= L'A' && ch <= L'Z') || (ch >= L'a' && ch <= L'z') ||
					(ch >= L'0' && ch <= L'9') || ch == L'-' || ch == L'.' || ch == L'_' ||
					ch == L'~' || ch == L'+' || ch == L'/';
				if (ch == L'=') {
					padding = true;
				} else if (!base || padding) {
					return false;
				} else {
					sawBaseCharacter = true;
				}
			}
			return sawBaseCharacter;
		}

		[[nodiscard]] bool IsStrictLoopbackHost(std::wstring_view host) noexcept
		{
			if (host == L"127.0.0.1") return true;
			return host == L"::1" || host == L"[::1]";
		}

		[[nodiscard]] bool IsUnreservedPathChar(wchar_t ch) noexcept
		{
			return (ch >= L'A' && ch <= L'Z') || (ch >= L'a' && ch <= L'z') ||
				(ch >= L'0' && ch <= L'9') || ch == L'-' || ch == L'.' || ch == L'_' || ch == L'~';
		}

		[[nodiscard]] bool IsPchar(wchar_t ch) noexcept
		{
			return IsUnreservedPathChar(ch) || ch == L'!' || ch == L'$' || ch == L'&' || ch == L'\'' ||
				ch == L'(' || ch == L')' || ch == L'*' || ch == L'+' || ch == L',' || ch == L';' ||
				ch == L'=' || ch == L':' || ch == L'@';
		}

		[[nodiscard]] int HexValue(wchar_t ch) noexcept
		{
			if (ch >= L'0' && ch <= L'9') return static_cast<int>(ch - L'0');
			if (ch >= L'a' && ch <= L'f') return static_cast<int>(ch - L'a') + 10;
			if (ch >= L'A' && ch <= L'F') return static_cast<int>(ch - L'A') + 10;
			return -1;
		}

		[[nodiscard]] bool IsSafeBasePath(std::wstring_view path)
		{
			if (path.empty()) return true;
			if (path.front() != L'/') return false;
			std::size_t segmentStart = 1;
			for (std::size_t i = 1; i <= path.size(); ++i) {
				if (i < path.size() && path[i] != L'/') {
					const wchar_t ch = path[i];
					if (ch <= 0x20 || ch >= 0x7F || ch == L'\\' || ch == L'?' || ch == L'#') return false;
					if (ch == L'%') {
						if (i + 2 >= path.size()) return false;
						const int high = HexValue(path[i + 1]);
						const int low = HexValue(path[i + 2]);
						if (high < 0 || low < 0) return false;
						const wchar_t decoded = static_cast<wchar_t>((high << 4) | low);
						if (decoded == L'/' || decoded == L'\\' || decoded <= 0x20 || decoded >= 0x7F ||
							decoded == L'?' || decoded == L'#' || decoded == L'%') return false;
						i += 2;
					} else if (!IsPchar(ch)) {
						return false;
					}
					continue;
				}

				const std::wstring_view segment = path.substr(segmentStart, i - segmentStart);
				if (segment.empty()) {
					if (i != path.size() || path.back() != L'/') return false;
				} else {
					std::wstring decoded;
					decoded.reserve(segment.size());
					for (std::size_t j = 0; j < segment.size(); ++j) {
						if (segment[j] == L'%') {
							const int high = HexValue(segment[j + 1]);
							const int low = HexValue(segment[j + 2]);
							decoded.push_back(static_cast<wchar_t>((high << 4) | low));
							j += 2;
						} else {
							decoded.push_back(segment[j]);
						}
					}
					if (decoded == L"." || decoded == L"..") return false;
				}
				segmentStart = i + 1;
			}
			return true;
		}

		[[nodiscard]] bool ParseBaseUrl(std::wstring_view base, ParsedBaseUrl& parsed)
		{
			if (base.empty() || base.size() > kMaxBaseUrlChars ||
				std::find(base.begin(), base.end(), L'\0') != base.end()) {
				return false;
			}
			for (const wchar_t ch : base) {
				if (ch <= 0x20 || ch == 0x7F || ch == L'\\') return false;
			}
			const auto schemeEnd = base.find(L"://");
			if (schemeEnd == std::wstring_view::npos) return false;
			const auto authorityStart = schemeEnd + 3;
			const auto authorityEnd = base.find_first_of(L"/?#", authorityStart);
			const auto authority = base.substr(authorityStart,
				authorityEnd == std::wstring_view::npos ? base.size() - authorityStart : authorityEnd - authorityStart);
			if (authority.empty() || authority.find(L'@') != std::wstring_view::npos ||
				authority.find(L'%') != std::wstring_view::npos) return false;
			std::wstring owned(base);
			std::array<wchar_t, kMaxBaseUrlChars + 1> schemeBuffer{};
			std::array<wchar_t, kMaxBaseUrlChars + 1> hostBuffer{};
			std::array<wchar_t, kMaxBaseUrlChars + 1> userBuffer{};
			std::array<wchar_t, kMaxBaseUrlChars + 1> passwordBuffer{};
			std::array<wchar_t, kMaxBaseUrlChars + 1> pathBuffer{};
			std::array<wchar_t, kMaxBaseUrlChars + 1> extraBuffer{};
			URL_COMPONENTS components{};
			components.dwStructSize = sizeof(components);
			components.lpszScheme = schemeBuffer.data();
			components.dwSchemeLength = static_cast<DWORD>(schemeBuffer.size());
			components.lpszHostName = hostBuffer.data();
			components.dwHostNameLength = static_cast<DWORD>(hostBuffer.size());
			components.lpszUserName = userBuffer.data();
			components.dwUserNameLength = static_cast<DWORD>(userBuffer.size());
			components.lpszPassword = passwordBuffer.data();
			components.dwPasswordLength = static_cast<DWORD>(passwordBuffer.size());
			components.lpszUrlPath = pathBuffer.data();
			components.dwUrlPathLength = static_cast<DWORD>(pathBuffer.size());
			components.lpszExtraInfo = extraBuffer.data();
			components.dwExtraInfoLength = static_cast<DWORD>(extraBuffer.size());
			if (!WinHttpCrackUrl(owned.c_str(), static_cast<DWORD>(owned.size()), 0, &components)) return false;
			if (!components.lpszHostName || components.dwHostNameLength == 0 ||
				components.dwUserNameLength != 0 || components.dwPasswordLength != 0 ||
				components.dwExtraInfoLength != 0 ||
				components.nPort == 0) {
				return false;
			}

			const std::wstring_view scheme(components.lpszScheme, components.dwSchemeLength);
			const bool secure = CompareStringOrdinal(scheme.data(), static_cast<int>(scheme.size()), L"https", 5, TRUE) == CSTR_EQUAL;
			const bool http = CompareStringOrdinal(scheme.data(), static_cast<int>(scheme.size()), L"http", 4, TRUE) == CSTR_EQUAL;
			const std::wstring_view host(components.lpszHostName, components.dwHostNameLength);
			const std::wstring_view path = components.dwUrlPathLength == 0 ? std::wstring_view{} :
				std::wstring_view(components.lpszUrlPath, components.dwUrlPathLength);
			if ((!secure && !http) || (http && !IsStrictLoopbackHost(host))) return false;
			if (!IsSafeBasePath(path)) return false;

			parsed.host.assign(host);
			parsed.pathPrefix.assign(path);
			if (parsed.pathPrefix == L"/") parsed.pathPrefix.clear();
			else if (!parsed.pathPrefix.empty() && parsed.pathPrefix.back() == L'/') parsed.pathPrefix.pop_back();
			parsed.port = components.nPort;
			parsed.secure = secure;
			return true;
		}

		[[nodiscard]] ErrorCode ValidateRequest(const HttpRequest& request)
		{
			if (!dql::policy::IsUuid(request.requestId) || !dql::policy::IsUuid(request.viewToken) ||
				!dql::policy::IsGeneration(request.nativeGeneration) ||
				!IsAllowedOperation(request.operation) || request.isWrite != IsWriteOperation(request.operation) ||
				request.envelopeJson.empty()) {
				return ErrorCode::invalidRequest;
			}
			if (request.envelopeJson.size() > HttpTransport::kMaxEnvelopeBytes) return ErrorCode::requestTooLarge;
			if (!IsValidUtf8JsonBytes(request.envelopeJson)) return ErrorCode::invalidRequest;
			if (request.bearerToken.empty()) {
				if (request.operation != "bootstrap") return ErrorCode::invalidBearer;
			} else if (!IsValidBearer(request.bearerToken)) {
				return ErrorCode::invalidBearer;
			}
			ParsedBaseUrl parsed;
			if (!ParseBaseUrl(request.serverBase, parsed)) return ErrorCode::invalidBaseUrl;
			return ErrorCode::none;
		}

		[[nodiscard]] HttpResult CancelledResult(bool dispatched, bool write) noexcept
		{
			return HttpResult{
				(dispatched && write) ? Outcome::unknown : Outcome::rejected,
				0,
				{},
				ErrorCode::cancelled
			};
		}

		[[nodiscard]] HttpResult FailureResult(ErrorCode error, bool dispatched, bool write,
			std::uint32_t status = 0) noexcept
		{
			return HttpResult{
				(dispatched && write) ? Outcome::unknown : Outcome::rejected,
				status,
				{},
				error
			};
		}

		[[nodiscard]] ErrorCode WinHttpErrorCode(DWORD error) noexcept
		{
			return error == ERROR_WINHTTP_TIMEOUT ? ErrorCode::timeout : ErrorCode::transportFailure;
		}

		[[nodiscard]] bool GetRemainingTimeoutMs(std::chrono::steady_clock::time_point deadline,
			DWORD& timeoutMs) noexcept
		{
			const auto now = std::chrono::steady_clock::now();
			if (now >= deadline) {
				SetLastError(ERROR_WINHTTP_TIMEOUT);
				return false;
			}
			const auto remaining = std::chrono::duration_cast<std::chrono::milliseconds>(deadline - now).count();
			timeoutMs = static_cast<DWORD>(std::clamp<std::int64_t>(remaining + 1, 1, 30'000));
			return true;
		}

		[[nodiscard]] bool SetRemainingTimeouts(HINTERNET handle,
			std::chrono::steady_clock::time_point deadline) noexcept
		{
			DWORD remainingMs = 0;
			if (!GetRemainingTimeoutMs(deadline, remainingMs)) return false;
			const int timeoutMs = static_cast<int>(std::min<DWORD>(remainingMs, kMaxPhaseTimeoutMs));
			if (!WinHttpSetTimeouts(handle, static_cast<int>(timeoutMs), static_cast<int>(timeoutMs),
				static_cast<int>(timeoutMs), static_cast<int>(timeoutMs))) {
				return false;
			}
			return true;
		}
	}

	class HttpTransport::Impl final
	{
	public:
		struct Job
		{
			explicit Job(HttpRequest value, Completion done) : request(std::move(value)), completion(std::move(done)) {}
			~Job()
			{
				if (!request.bearerToken.empty()) {
					SecureZeroMemory(request.bearerToken.data(), request.bearerToken.size() * sizeof(wchar_t));
					request.bearerToken.clear();
				}
				if (!request.envelopeJson.empty()) {
					SecureZeroMemory(request.envelopeJson.data(), request.envelopeJson.size());
					request.envelopeJson.clear();
				}
			}
			HttpRequest request;
			Completion completion;
			bool cancelled{};
			bool dispatched{};
			bool deliveryStarted{};
		};

		explicit Impl(std::size_t maxPending) : maxPending_(std::clamp<std::size_t>(maxPending, 1, kMaxPendingRequests)),
			worker_([this] { Run(); })
		{}

		~Impl() { Shutdown(); }

		[[nodiscard]] SubmitResult Submit(HttpRequest request, Completion completion)
		{
			WipeWideString wipeToken(request.bearerToken);
			WipeString wipeEnvelope(request.envelopeJson);
			try {
				const ErrorCode validation = ValidateRequest(request);
				if (validation != ErrorCode::none || !completion) {
					return { false, validation == ErrorCode::none ? ErrorCode::invalidRequest : validation };
				}
				auto job = std::make_shared<Job>(std::move(request), std::move(completion));
				std::lock_guard lock(mutex_);
				if (stopping_) return { false, ErrorCode::shuttingDown };
				if (pendingCount_ >= maxPending_) return { false, ErrorCode::queueFull };
				queue_.push_back(std::move(job));
				++pendingCount_;
				condition_.notify_one();
				return { true, ErrorCode::none };
			} catch (...) {
				return { false, ErrorCode::transportFailure };
			}
		}

		void CancelView(std::string_view viewToken, std::string_view nativeGeneration,
			bool invalidateGeneration) noexcept
		{
			std::lock_guard lock(mutex_);
			for (const auto& job : queue_) {
				if (Matches(*job, viewToken, nativeGeneration) &&
					(!job->request.isWrite || invalidateGeneration)) {
					job->cancelled = true;
				}
			}
			if (active_ && !active_->deliveryStarted && Matches(*active_, viewToken, nativeGeneration) &&
				(!active_->request.isWrite || (invalidateGeneration && !active_->dispatched))) {
				active_->cancelled = true;
			}
			condition_.notify_all();
		}

		void Shutdown() noexcept
		{
			std::lock_guard shutdownLock(shutdownMutex_);
			{
				std::lock_guard lock(mutex_);
				if (!stopping_) {
					stopping_ = true;
					for (const auto& job : queue_) job->cancelled = true;
					if (active_ && !active_->deliveryStarted) active_->cancelled = true;
				}
			}
			condition_.notify_all();
			if (worker_.joinable() && worker_.get_id() != std::this_thread::get_id()) worker_.join();
		}

	private:
		[[nodiscard]] static bool Matches(const Job& job, std::string_view viewToken,
			std::string_view nativeGeneration) noexcept
		{
			return job.request.viewToken == viewToken && job.request.nativeGeneration == nativeGeneration;
		}

		[[nodiscard]] bool IsCancelled(const std::shared_ptr<Job>& job) noexcept
		{
			std::lock_guard lock(mutex_);
			return job->cancelled;
		}

		[[nodiscard]] bool MarkDispatched(const std::shared_ptr<Job>& job) noexcept
		{
			std::lock_guard lock(mutex_);
			if (job->cancelled || stopping_) return false;
			job->dispatched = true;
			return true;
		}

		[[nodiscard]] HttpResult Execute(const std::shared_ptr<Job>& job)
		{
			const auto deadline = std::chrono::steady_clock::now() + kTransactionTimeout;
			auto& request = job->request;
			WipeWideString wipeBearer(request.bearerToken);
			if (IsCancelled(job)) return CancelledResult(false, request.isWrite);

			ParsedBaseUrl base;
			if (!ParseBaseUrl(request.serverBase, base)) return FailureResult(ErrorCode::invalidBaseUrl, false, request.isWrite);

			InternetHandle session(WinHttpOpen(L"WorldOfDramaLlama/1.0", WINHTTP_ACCESS_TYPE_NO_PROXY,
				WINHTTP_NO_PROXY_NAME, WINHTTP_NO_PROXY_BYPASS, WINHTTP_FLAG_ASYNC));
			if (!session) return FailureResult(ErrorCode::transportFailure, false, request.isWrite);
			DWORD autoLogonPolicy = WINHTTP_AUTOLOGON_SECURITY_LEVEL_HIGH;
			if (!WinHttpSetOption(session.get(), WINHTTP_OPTION_AUTOLOGON_POLICY,
				&autoLogonPolicy, sizeof(autoLogonPolicy))) {
				return FailureResult(ErrorCode::transportFailure, false, request.isWrite);
			}
			if (IsCancelled(job)) return CancelledResult(false, request.isWrite);

			if (!SetRemainingTimeouts(session.get(), deadline)) {
				return FailureResult(WinHttpErrorCode(GetLastError()), false, request.isWrite);
			}
			InternetHandle connection(WinHttpConnect(session.get(), base.host.c_str(), base.port, 0));
			if (!connection) return FailureResult(WinHttpErrorCode(GetLastError()), false, request.isWrite);
			std::wstring requestPath = base.pathPrefix;
			requestPath.append(kFixedPath);
			InternetHandle httpRequest(WinHttpOpenRequest(connection.get(), L"POST", requestPath.c_str(), nullptr,
				WINHTTP_NO_REFERER, WINHTTP_DEFAULT_ACCEPT_TYPES,
				base.secure ? WINHTTP_FLAG_SECURE : 0));
			if (!httpRequest) return FailureResult(ErrorCode::transportFailure, false, request.isWrite);

			auto asyncState = std::make_shared<AsyncRequestState>();
			asyncState->uploadBody = std::move(request.envelopeJson);
			asyncState->headers = L"Content-Type: application/json; charset=utf-8\r\nAccept: application/json\r\n";
			if (!request.bearerToken.empty()) {
				asyncState->headers.append(L"Authorization: Bearer ");
				asyncState->headers.append(request.bearerToken);
				asyncState->headers.append(L"\r\n");
			}
			if (!request.envelopeJson.empty()) {
				SecureZeroMemory(request.envelopeJson.data(), request.envelopeJson.size());
				request.envelopeJson.clear();
			}
			if (!request.bearerToken.empty()) {
				SecureZeroMemory(request.bearerToken.data(), request.bearerToken.size() * sizeof(wchar_t));
				request.bearerToken.clear();
			}
			RegisterAsyncState(asyncState);
			bool callbackInstalled = false;
			auto closeRequest = [&]() noexcept {
				if (!httpRequest) return;
				auto* handle = httpRequest.release();
				WinHttpCloseHandle(handle);
				if (callbackInstalled) WaitForHandleClosing(*asyncState);
				else UnregisterAsyncState(asyncState.get());
				callbackInstalled = false;
			};

			DWORD_PTR contextValue = reinterpret_cast<DWORD_PTR>(asyncState.get());
			if (!WinHttpSetOption(httpRequest.get(), WINHTTP_OPTION_CONTEXT_VALUE,
				&contextValue, sizeof(contextValue))) {
				const DWORD error = GetLastError();
				closeRequest();
				return FailureResult(WinHttpErrorCode(error), false, request.isWrite);
			}
			const DWORD callbackFlags = WINHTTP_CALLBACK_FLAG_ALL_COMPLETIONS |
				WINHTTP_CALLBACK_FLAG_HANDLES | WINHTTP_CALLBACK_FLAG_SECURE_FAILURE;
			if (WinHttpSetStatusCallback(httpRequest.get(), WinHttpStatusCallback, callbackFlags, 0) ==
				WINHTTP_INVALID_STATUS_CALLBACK) {
				const DWORD error = GetLastError();
				closeRequest();
				return FailureResult(WinHttpErrorCode(error), false, request.isWrite);
			}
			callbackInstalled = true;

			DWORD disabledFeatures = WINHTTP_DISABLE_AUTHENTICATION | WINHTTP_DISABLE_COOKIES | WINHTTP_DISABLE_REDIRECTS;
			DWORD redirectPolicy = WINHTTP_OPTION_REDIRECT_POLICY_NEVER;
			if (!WinHttpSetOption(httpRequest.get(), WINHTTP_OPTION_DISABLE_FEATURE,
				&disabledFeatures, sizeof(disabledFeatures)) ||
				!WinHttpSetOption(httpRequest.get(), WINHTTP_OPTION_REDIRECT_POLICY,
				&redirectPolicy, sizeof(redirectPolicy))) {
				const DWORD error = GetLastError();
				closeRequest();
				return FailureResult(WinHttpErrorCode(error), false, request.isWrite);
			}
			DWORD maxHeaderBytes = kMaxResponseHeaderBytes;
			if (!WinHttpSetOption(httpRequest.get(), WINHTTP_OPTION_MAX_RESPONSE_HEADER_SIZE,
				&maxHeaderBytes, sizeof(maxHeaderBytes))) {
				const DWORD error = GetLastError();
				closeRequest();
				return FailureResult(WinHttpErrorCode(error), false, request.isWrite);
			}

			auto waitFor = [&](DWORD expectedStatus, auto&& startOperation, DWORD& transferred) {
				asyncState->Prepare(expectedStatus);
				const BOOL started = startOperation();
				const DWORD startError = started ? ERROR_SUCCESS : GetLastError();
				if (!started && startError != ERROR_IO_PENDING) {
					std::lock_guard lock(asyncState->mutex);
					asyncState->operationError = startError;
					asyncState->operationComplete = true;
				}
				DWORD operationError = ERROR_SUCCESS;
				const AsyncWaitResult waited = WaitForAsyncOperation(*asyncState, deadline,
					[this, &job] { return IsCancelled(job); }, operationError, transferred);
				if (waited == AsyncWaitResult::cancelled) return std::pair<AsyncWaitResult, DWORD>{ AsyncWaitResult::cancelled, ERROR_WINHTTP_OPERATION_CANCELLED };
				if (waited == AsyncWaitResult::timedOut) return std::pair<AsyncWaitResult, DWORD>{ AsyncWaitResult::timedOut, ERROR_WINHTTP_TIMEOUT };
				return std::pair<AsyncWaitResult, DWORD>{ AsyncWaitResult::completed, operationError };
			};
			auto failAsync = [&](AsyncWaitResult waited, DWORD error, std::uint32_t status = 0) {
				closeRequest();
				if (waited == AsyncWaitResult::cancelled) return CancelledResult(job->dispatched, request.isWrite);
				return FailureResult(WinHttpErrorCode(error), job->dispatched, request.isWrite, status);
			};

			const DWORD bodyBytes = static_cast<DWORD>(asyncState->uploadBody.size());
			DWORD transferred = 0;
			if (!SetRemainingTimeouts(httpRequest.get(), deadline)) {
				const DWORD error = GetLastError();
				closeRequest();
				return FailureResult(WinHttpErrorCode(error), false, request.isWrite);
			}
			if (!MarkDispatched(job)) {
				closeRequest();
				return CancelledResult(false, request.isWrite);
			}
			auto send = waitFor(WINHTTP_CALLBACK_STATUS_SENDREQUEST_COMPLETE, [&] {
				return WinHttpSendRequest(httpRequest.get(), asyncState->headers.data(),
					static_cast<DWORD>(asyncState->headers.size()), WINHTTP_NO_REQUEST_DATA, 0,
					bodyBytes, reinterpret_cast<DWORD_PTR>(asyncState.get()));
			}, transferred);
			if (send.first != AsyncWaitResult::completed || send.second != ERROR_SUCCESS) {
				return failAsync(send.first, send.second);
			}

			std::size_t bodyOffset = 0;
			while (bodyOffset < asyncState->uploadBody.size()) {
				if (!SetRemainingTimeouts(httpRequest.get(), deadline)) {
					return failAsync(AsyncWaitResult::completed, GetLastError());
				}
				const DWORD remaining = static_cast<DWORD>(asyncState->uploadBody.size() - bodyOffset);
				auto write = waitFor(WINHTTP_CALLBACK_STATUS_WRITE_COMPLETE, [&] {
					return WinHttpWriteData(httpRequest.get(), asyncState->uploadBody.data() + bodyOffset,
						remaining, nullptr);
				}, transferred);
				if (write.first != AsyncWaitResult::completed || write.second != ERROR_SUCCESS) {
					return failAsync(write.first, write.second);
				}
				if (transferred == 0 || transferred > remaining) {
					return failAsync(AsyncWaitResult::completed, ERROR_WINHTTP_INTERNAL_ERROR);
				}
				bodyOffset += transferred;
			}

			DWORD receiveResponseTimeoutMs = 0;
			if (!GetRemainingTimeoutMs(deadline, receiveResponseTimeoutMs) ||
				!WinHttpSetOption(httpRequest.get(), WINHTTP_OPTION_RECEIVE_RESPONSE_TIMEOUT,
					&receiveResponseTimeoutMs, sizeof(receiveResponseTimeoutMs)) ||
				!SetRemainingTimeouts(httpRequest.get(), deadline)) {
				return failAsync(AsyncWaitResult::completed, GetLastError());
			}
			auto receive = waitFor(WINHTTP_CALLBACK_STATUS_HEADERS_AVAILABLE, [&] {
				return WinHttpReceiveResponse(httpRequest.get(), nullptr);
			}, transferred);
			if (receive.first != AsyncWaitResult::completed || receive.second != ERROR_SUCCESS) {
				return failAsync(receive.first, receive.second);
			}

			DWORD statusCode = 0;
			DWORD statusBytes = sizeof(statusCode);
			if (!SetRemainingTimeouts(httpRequest.get(), deadline)) {
				return failAsync(AsyncWaitResult::completed, GetLastError());
			}
			if (!WinHttpQueryHeaders(httpRequest.get(), WINHTTP_QUERY_STATUS_CODE | WINHTTP_QUERY_FLAG_NUMBER,
				WINHTTP_HEADER_NAME_BY_INDEX, &statusCode, &statusBytes, WINHTTP_NO_HEADER_INDEX)) {
				return failAsync(AsyncWaitResult::completed, GetLastError());
			}

			std::string responseBody;
			for (;;) {
				if (!SetRemainingTimeouts(httpRequest.get(), deadline)) {
				return failAsync(AsyncWaitResult::completed, GetLastError(), statusCode);
				}
				const std::size_t remaining = kMaxResponseBytes - responseBody.size();
				const DWORD wanted = static_cast<DWORD>(std::min<std::size_t>(remaining + 1, asyncState->readBuffer.size()));
				auto read = waitFor(WINHTTP_CALLBACK_STATUS_READ_COMPLETE, [&] {
					return WinHttpReadData(httpRequest.get(), asyncState->readBuffer.data(), wanted, nullptr);
				}, transferred);
				if (read.first != AsyncWaitResult::completed || read.second != ERROR_SUCCESS) {
					return failAsync(read.first, read.second, statusCode);
				}
				if (transferred == 0) break;
				if (transferred > remaining) {
					closeRequest();
					return FailureResult(ErrorCode::responseTooLarge, true, request.isWrite, statusCode);
				}
				responseBody.append(asyncState->readBuffer.data(), transferred);
			}
			if (std::chrono::steady_clock::now() >= deadline) {
				closeRequest();
				return FailureResult(ErrorCode::timeout, true, request.isWrite, statusCode);
			}
			closeRequest();
			return HttpResult{ Outcome::completed, statusCode, std::move(responseBody), ErrorCode::none };
		}

		void Run() noexcept
		{
			for (;;) {
				std::shared_ptr<Job> job;
				{
					std::unique_lock lock(mutex_);
					condition_.wait(lock, [this] { return stopping_ || !queue_.empty(); });
					if (queue_.empty()) {
						if (stopping_) return;
						continue;
					}
					job = std::move(queue_.front());
					queue_.pop_front();
					active_ = job;
				}

				HttpResult result;
				try {
					result = Execute(job);
				} catch (...) {
					result = FailureResult(ErrorCode::transportFailure, job->dispatched, job->request.isWrite);
				}

				{
					std::lock_guard lock(mutex_);
					if (job->cancelled) result = CancelledResult(job->dispatched, job->request.isWrite);
					job->deliveryStarted = true;
				}
				try {
					job->completion(std::move(result));
				} catch (...) {
					// Completion code belongs to the caller; keep the worker alive without logging details.
				}
				{
					std::lock_guard lock(mutex_);
					if (active_ == job) active_.reset();
					if (pendingCount_ > 0) --pendingCount_;
				}
				condition_.notify_all();
			}
		}

		const std::size_t maxPending_;
		std::mutex mutex_;
		std::mutex shutdownMutex_;
		std::condition_variable condition_;
		std::deque<std::shared_ptr<Job>> queue_;
		std::shared_ptr<Job> active_;
		std::size_t pendingCount_{};
		bool stopping_{};
		std::thread worker_;
	};

	HttpTransport::HttpTransport(std::size_t maxPending) : impl_(std::make_unique<Impl>(maxPending)) {}
	HttpTransport::~HttpTransport() = default;

	SubmitResult HttpTransport::Submit(HttpRequest request, Completion completion)
	{
		if (!impl_) return { false, ErrorCode::shuttingDown };
		return impl_->Submit(std::move(request), std::move(completion));
	}

	void HttpTransport::CancelView(std::string_view viewToken, std::string_view nativeGeneration,
		bool invalidateGeneration) noexcept
	{
		if (impl_) impl_->CancelView(viewToken, nativeGeneration, invalidateGeneration);
	}

	void HttpTransport::Shutdown() noexcept
	{
		if (impl_) impl_->Shutdown();
	}
}
