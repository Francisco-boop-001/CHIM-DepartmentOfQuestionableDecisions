#ifndef NOMINMAX
#define NOMINMAX
#endif
#include <winsock2.h>
#include <ws2tcpip.h>

#include "HttpTransport.h"

#include <algorithm>
#include <atomic>
#include <cctype>
#include <chrono>
#include <condition_variable>
#include <cstdint>
#include <future>
#include <iostream>
#include <mutex>
#include <string>
#include <thread>
#include <utility>

namespace
{
	using namespace std::chrono_literals;
using dql::http::ErrorCode;
using dql::http::HttpRequest;
using dql::http::HttpResult;
using dql::http::HttpTransport;
using dql::http::Outcome;

	void Check(bool condition, const char* message)
	{
		if (!condition) {
			std::cerr << "FAIL: " << message << '\n';
			std::exit(1);
		}
	}

	std::string Response(unsigned status, std::string_view reason, std::string body)
	{
		return "HTTP/1.1 " + std::to_string(status) + " " + std::string(reason) +
			"\r\nContent-Type: application/json\r\nContent-Length: " +
		std::to_string(body.size()) + "\r\nConnection: close\r\n\r\n" + body;
	}

	class LocalServer
	{
	public:
		LocalServer(std::string response, bool hold = false, bool watchAdditional = false,
			std::size_t bodyChunkSize = 0, std::chrono::milliseconds bodyChunkDelay = 0ms,
			std::size_t headerChunkSize = 0, std::chrono::milliseconds headerChunkDelay = 0ms) :
			response_(std::move(response)), watchAdditional_(watchAdditional),
			bodyChunkSize_(bodyChunkSize), bodyChunkDelay_(bodyChunkDelay),
			headerChunkSize_(headerChunkSize), headerChunkDelay_(headerChunkDelay)
		{
			WSADATA data{};
			Check(WSAStartup(MAKEWORD(2, 2), &data) == 0, "WSAStartup succeeds");
			listener_ = socket(AF_INET, SOCK_STREAM, IPPROTO_TCP);
			Check(listener_ != INVALID_SOCKET, "loopback listener socket opens");
			sockaddr_in address{};
			address.sin_family = AF_INET;
			address.sin_addr.s_addr = htonl(INADDR_LOOPBACK);
			address.sin_port = 0;
			Check(bind(listener_, reinterpret_cast<sockaddr*>(&address), sizeof(address)) == 0,
				"loopback listener binds");
			Check(listen(listener_, 4) == 0, "loopback listener starts");
			int addressLength = sizeof(address);
			Check(getsockname(listener_, reinterpret_cast<sockaddr*>(&address), &addressLength) == 0,
				"loopback port is assigned");
			port_ = ntohs(address.sin_port);
			released_ = !hold;
			worker_ = std::thread([this] { ServeOne(); });
		}

		~LocalServer()
		{
			{
				std::lock_guard lock(mutex_);
				stopping_ = true;
				released_ = true;
			}
			condition_.notify_all();
			if (listener_ != INVALID_SOCKET) {
				shutdown(listener_, SD_BOTH);
				closesocket(listener_);
			}
			if (worker_.joinable()) worker_.join();
			WSACleanup();
		}

		LocalServer(const LocalServer&) = delete;
		LocalServer& operator=(const LocalServer&) = delete;

		[[nodiscard]] std::uint16_t Port() const noexcept { return port_; }

		[[nodiscard]] std::wstring BaseUrl() const
		{
			return L"http://127.0.0.1:" + std::to_wstring(port_);
		}

		bool WaitForRequest(std::chrono::milliseconds timeout = 3000ms)
		{
			std::unique_lock lock(mutex_);
			return condition_.wait_for(lock, timeout, [this] { return requestReady_ || stopping_; }) && requestReady_;
		}

		bool WaitForRequestCount(int count, std::chrono::milliseconds timeout = 1000ms)
		{
			std::unique_lock lock(mutex_);
			return condition_.wait_for(lock, timeout, [this, count] { return requestCount_.load() >= count || stopping_; }) &&
				requestCount_.load() >= count;
		}

		void Release()
		{
			{
				std::lock_guard lock(mutex_);
				released_ = true;
			}
			condition_.notify_all();
		}

		[[nodiscard]] std::string RequestText() const
		{
			std::lock_guard lock(mutex_);
			return request_;
		}

		[[nodiscard]] int RequestCount() const noexcept { return requestCount_.load(); }

	private:
		void ServeOne()
		{
			SOCKET client = accept(listener_, nullptr, nullptr);
			if (client == INVALID_SOCKET) return;
			requestCount_.fetch_add(1);
			condition_.notify_all();
			std::string request;
			char buffer[4096];
			std::size_t expectedSize = 0;
			for (;;) {
				const int count = recv(client, buffer, sizeof(buffer), 0);
				if (count <= 0) break;
				request.append(buffer, static_cast<std::size_t>(count));
				const auto headersEnd = request.find("\r\n\r\n");
				if (headersEnd != std::string::npos) {
					std::string lowered = request.substr(0, headersEnd);
					std::transform(lowered.begin(), lowered.end(), lowered.begin(),
						[](unsigned char ch) { return static_cast<char>(std::tolower(ch)); });
					const auto lengthStart = lowered.find("content-length:");
					if (lengthStart != std::string::npos) {
						const auto digits = lengthStart + std::string_view("content-length:").size();
						expectedSize = headersEnd + 4 + static_cast<std::size_t>(std::stoul(request.substr(digits)));
					} else {
						expectedSize = headersEnd + 4;
					}
					if (request.size() >= expectedSize) break;
				}
				if (request.size() > 70000) break;
			}
			{
				std::lock_guard lock(mutex_);
				request_ = std::move(request);
				requestReady_ = true;
			}
			condition_.notify_all();
			{
				std::unique_lock lock(mutex_);
				condition_.wait(lock, [this] { return released_ || stopping_; });
			}
			if (!response_.empty()) {
				const auto headersEnd = response_.find("\r\n\r\n");
				const std::size_t bodyStart = headersEnd == std::string::npos ? response_.size() : headersEnd + 4;
				std::size_t sent = 0;
				while (sent < bodyStart) {
					const std::size_t chunkSize = headerChunkSize_ == 0 ? bodyStart : headerChunkSize_;
					const std::size_t wanted = std::min(chunkSize, bodyStart - sent);
					const int count = send(client, response_.data() + sent, static_cast<int>(wanted), 0);
					if (count <= 0) break;
					sent += static_cast<std::size_t>(count);
					if (sent < bodyStart && headerChunkDelay_.count() > 0) {
						std::unique_lock lock(mutex_);
						if (condition_.wait_for(lock, headerChunkDelay_, [this] { return stopping_; })) break;
					}
				}
				if (sent == bodyStart) {
					const std::size_t chunkSize = bodyChunkSize_ == 0 ? response_.size() : bodyChunkSize_;
					while (sent < response_.size()) {
						const std::size_t wanted = std::min(chunkSize, response_.size() - sent);
						const int count = send(client, response_.data() + sent, static_cast<int>(wanted), 0);
						if (count <= 0) break;
						sent += static_cast<std::size_t>(count);
						if (sent < response_.size() && bodyChunkDelay_.count() > 0) {
							std::unique_lock lock(mutex_);
							if (condition_.wait_for(lock, bodyChunkDelay_, [this] { return stopping_; })) break;
						}
					}
				}
			}
			shutdown(client, SD_BOTH);
			closesocket(client);
			if (watchAdditional_) {
				fd_set readable;
				FD_ZERO(&readable);
				FD_SET(listener_, &readable);
				timeval timeout{ 0, 700000 };
				if (select(0, &readable, nullptr, nullptr, &timeout) > 0) {
					SOCKET extra = accept(listener_, nullptr, nullptr);
					if (extra != INVALID_SOCKET) {
						requestCount_.fetch_add(1);
						condition_.notify_all();
						shutdown(extra, SD_BOTH);
						closesocket(extra);
					}
				}
			}
		}

		SOCKET listener_{ INVALID_SOCKET };
		std::uint16_t port_{};
		std::string response_;
		std::thread worker_;
		mutable std::mutex mutex_;
		std::condition_variable condition_;
		std::string request_;
		bool requestReady_{};
		bool released_{};
		bool stopping_{};
		std::atomic<int> requestCount_{};
		bool watchAdditional_{};
		std::size_t bodyChunkSize_{};
		std::chrono::milliseconds bodyChunkDelay_{};
		std::size_t headerChunkSize_{};
		std::chrono::milliseconds headerChunkDelay_{};
	};

	HttpRequest MakeRequest(const std::wstring& base, std::string operation = "bootstrap", bool isWrite = false)
	{
		HttpRequest request;
		request.requestId = "11111111-1111-4111-8111-111111111111";
		request.viewToken = "22222222-2222-4222-8222-222222222222";
		request.nativeGeneration = "7";
		request.operation = std::move(operation);
		request.envelopeJson = "{\"version\":1,\"operation\":\"bootstrap\"}";
		request.serverBase = base;
		request.bearerToken = L"qd1_test-token_123";
		request.isWrite = isWrite;
		return request;
	}

	HttpResult AwaitResult(std::future<HttpResult>& result, std::chrono::seconds timeout = 10s)
	{
		Check(result.wait_for(timeout) == std::future_status::ready, "completion arrives before timeout");
		return result.get();
	}

	void TestFixedPostAndRedirectPolicy()
	{
		LocalServer redirected(Response(200, "OK", "should-not-be-read"));
		const std::string redirectResponse = "HTTP/1.1 302 Found\r\nLocation: http://127.0.0.1:" +
			std::to_string(redirected.Port()) + "/capture\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
		LocalServer redirectSource(redirectResponse);
		std::promise<HttpResult> promise;
		auto result = promise.get_future();
		std::atomic<int> calls{};
		const auto caller = std::this_thread::get_id();
		std::thread::id callbackThread;
		HttpTransport transport;
		auto request = MakeRequest(redirectSource.BaseUrl());
		const auto submitted = transport.Submit(std::move(request), [&](HttpResult value) {
			callbackThread = std::this_thread::get_id();
			calls.fetch_add(1);
			promise.set_value(std::move(value));
		});
		if (!submitted.queued) std::cerr << "Submit error code: " << static_cast<int>(submitted.errorCode) << '\n';
		Check(submitted.queued, "valid bootstrap request queues");
		const auto response = AwaitResult(result);
		Check(response.outcome == Outcome::completed && response.statusCode == 302,
			"redirect is returned as an HTTP response instead of followed");
		Check(response.body.empty(), "empty redirect body remains empty");
		Check(redirectSource.WaitForRequest(), "fixed endpoint receives request");
		const auto sent = redirectSource.RequestText();
		Check(sent.starts_with("POST /ext/questionable_decisions/api.php HTTP/1.1\r\n"),
			"request uses fixed POST endpoint");
		Check(sent.find("Authorization: Bearer qd1_test-token_123\r\n") != std::string::npos,
			"native bearer is sent only in Authorization header");
		Check(sent.find("{\"version\":1,\"operation\":\"bootstrap\"}") != std::string::npos,
			"pre-normalized JSON body is transmitted unchanged");
		Check(callbackThread != caller, "completion runs off the submitter thread");
		Check(!redirected.WaitForRequest(250ms), "WinHTTP does not follow redirects");
		transport.Shutdown();
		Check(calls.load() == 1, "accepted request completes exactly once");
	}

	void TestRejectsUnsafeRequestInputsBeforeNetwork()
	{
		LocalServer server(Response(200, "OK", "{}"));
		HttpTransport transport;
		auto reject = [&](HttpRequest request, ErrorCode expected) {
			std::atomic<bool> called{};
			const auto submitted = transport.Submit(std::move(request), [&](HttpResult) { called = true; });
			Check(!submitted.queued && submitted.errorCode == expected,
				"unsafe request is rejected synchronously with a fixed error code");
			Check(!called.load(), "rejected Submit never invokes completion");
		};

		auto remoteHttp = MakeRequest(L"http://example.com");
		reject(std::move(remoteHttp), ErrorCode::invalidBaseUrl);
		auto traversal = MakeRequest(server.BaseUrl() + L"/mount/%2e%2E/route");
		reject(std::move(traversal), ErrorCode::invalidBaseUrl);
		auto doubleEncodedTraversal = MakeRequest(server.BaseUrl() + L"/mount/%252e%252e/route");
		reject(std::move(doubleEncodedTraversal), ErrorCode::invalidBaseUrl);
		auto encodedSlash = MakeRequest(server.BaseUrl() + L"/mount%2foutside");
		reject(std::move(encodedSlash), ErrorCode::invalidBaseUrl);
		auto escapedHost = MakeRequest(L"https://example%40host.invalid");
		reject(std::move(escapedHost), ErrorCode::invalidBaseUrl);
		auto emptyUserInfo = MakeRequest(L"https://@example.invalid");
		reject(std::move(emptyUserInfo), ErrorCode::invalidBaseUrl);
		auto query = MakeRequest(server.BaseUrl() + L"/mount?route=/other");
		reject(std::move(query), ErrorCode::invalidBaseUrl);
		auto fragment = MakeRequest(server.BaseUrl() + L"/mount#other");
		reject(std::move(fragment), ErrorCode::invalidBaseUrl);
		auto backslash = MakeRequest(server.BaseUrl() + L"\\mount");
		reject(std::move(backslash), ErrorCode::invalidBaseUrl);
		auto nonLoopbackHttp = MakeRequest(L"http://127.0.0.2");
		reject(std::move(nonLoopbackHttp), ErrorCode::invalidBaseUrl);
		auto userInfo = MakeRequest(L"https://user:password@example.com");
		reject(std::move(userInfo), ErrorCode::invalidBaseUrl);
		auto authInjection = MakeRequest(server.BaseUrl());
		authInjection.bearerToken = L"qd1_safe\r\nX-Injected: yes";
		reject(std::move(authInjection), ErrorCode::invalidBearer);
		auto unsupported = MakeRequest(server.BaseUrl(), "view.close");
		reject(std::move(unsupported), ErrorCode::invalidRequest);
		auto wrongWriteFlag = MakeRequest(server.BaseUrl(), "pcv.arm", false);
		reject(std::move(wrongWriteFlag), ErrorCode::invalidRequest);
		auto tooLarge = MakeRequest(server.BaseUrl());
		tooLarge.envelopeJson.assign(65537, 'x');
		reject(std::move(tooLarge), ErrorCode::requestTooLarge);
		Check(!server.WaitForRequest(250ms), "invalid inputs never open a network request");
		transport.Shutdown();
	}

	void TestInstallationPathPrefixIsPreserved()
	{
		LocalServer server(Response(200, "OK", "{}"));
		std::promise<HttpResult> promise;
		auto result = promise.get_future();
		HttpTransport transport;
		auto request = MakeRequest(server.BaseUrl() + L"/HerikaServer/");
		const auto submitted = transport.Submit(std::move(request),
			[&](HttpResult value) { promise.set_value(std::move(value)); });
		Check(submitted.queued, "validated installation base path is accepted");
		const auto response = AwaitResult(result);
		Check(response.outcome == Outcome::completed && response.statusCode == 200,
			"installation-prefix request completes");
		Check(server.WaitForRequest(), "installation-prefix request reaches loopback fixture");
		Check(server.RequestText().starts_with(
			"POST /HerikaServer/ext/questionable_decisions/api.php HTTP/1.1\r\n"),
			"validated base path is preserved before the fixed endpoint");
		transport.Shutdown();
	}

	void TestUnauthenticatedBootstrapOmitsAuthorization()
	{
		LocalServer server(Response(200, "OK", "{\"authenticated\":false}"));
		std::promise<HttpResult> promise;
		auto result = promise.get_future();
		HttpTransport transport;
		auto request = MakeRequest(server.BaseUrl());
		request.bearerToken.clear();
		const auto submitted = transport.Submit(std::move(request),
			[&](HttpResult value) { promise.set_value(std::move(value)); });
		Check(submitted.queued, "unauthenticated bootstrap can query public status without a paired token");
		const auto response = AwaitResult(result);
		Check(response.outcome == Outcome::completed && response.statusCode == 200,
			"unauthenticated bootstrap returns the public server projection");
		Check(server.WaitForRequest(), "unauthenticated bootstrap reaches the loopback fixture");
		const auto sent = server.RequestText();
		Check(sent.find("Authorization:") == std::string::npos,
			"public bootstrap omits the Authorization header when no paired token exists");
		transport.Shutdown();
	}

	void TestResponseLimit()
	{
		std::string oversized(65537, 'x');
		LocalServer server(Response(200, "OK", std::move(oversized)));
		std::promise<HttpResult> promise;
		auto result = promise.get_future();
		HttpTransport transport;
		const auto submitted = transport.Submit(MakeRequest(server.BaseUrl()),
			[&](HttpResult value) { promise.set_value(std::move(value)); });
		Check(submitted.queued, "bounded response request queues");
		const auto response = AwaitResult(result);
		Check(response.outcome == Outcome::rejected && response.statusCode == 200,
			"oversized read is rejected while preserving the known HTTP status");
		Check(response.errorCode == ErrorCode::responseTooLarge && response.body.empty(),
			"oversized body is rejected without returning partial content");
		transport.Shutdown();
	}

	void TestCloseCancelsReadsButPreservesAcceptedWrites()
	{
		for (const bool write : { false, true }) {
			LocalServer server(Response(200, "OK", "{}"), true);
			std::promise<HttpResult> promise;
			auto result = promise.get_future();
			HttpTransport transport;
			const auto op = write ? "correspondence.compose" : "mp.relationships.read";
			const auto submitted = transport.Submit(MakeRequest(server.BaseUrl(), op, write),
				[&](HttpResult value) { promise.set_value(std::move(value)); });
			Check(submitted.queued, "cancel test request queues");
			Check(server.WaitForRequest(), "server observes dispatch before cancellation");
			transport.CancelView("22222222-2222-4222-8222-222222222222", "7");
			server.Release();
			const auto response = AwaitResult(result);
			if (write) {
				Check(response.outcome == Outcome::completed && response.statusCode == 200 && response.body == "{}",
					"ordinary close preserves a dispatched write through its known response");
			} else {
				Check(response.outcome == Outcome::rejected && response.errorCode == ErrorCode::cancelled,
					"cancelled read is rejected");
			}
			Check(server.RequestCount() == 1, "cancellation never retries the request");
			transport.Shutdown();
		}
	}

	void TestGenerationInvalidationCancelsQueuedWrites()
	{
		LocalServer server(Response(200, "OK", "{}"), true, true);
		std::promise<HttpResult> readPromise;
		std::promise<HttpResult> writePromise;
		auto readResult = readPromise.get_future();
		auto writeResult = writePromise.get_future();
		HttpTransport transport(2);
		const auto readSubmit = transport.Submit(MakeRequest(server.BaseUrl(), "mp.relationships.read"),
			[&](HttpResult value) { readPromise.set_value(std::move(value)); });
		Check(readSubmit.queued, "active read queues before generation invalidation");
		Check(server.WaitForRequest(), "fixture observes active read");
		const auto writeSubmit = transport.Submit(MakeRequest(server.BaseUrl(), "correspondence.compose", true),
			[&](HttpResult value) { writePromise.set_value(std::move(value)); });
		Check(writeSubmit.queued, "old-generation write queues behind active read");
		transport.CancelView("22222222-2222-4222-8222-222222222222", "7", true);
		server.Release();
		const auto read = AwaitResult(readResult);
		const auto write = AwaitResult(writeResult);
		Check(read.outcome == Outcome::rejected && read.errorCode == ErrorCode::cancelled,
			"generation invalidation cancels the old read");
		Check(write.outcome == Outcome::rejected && write.errorCode == ErrorCode::cancelled,
			"queued old-generation write is rejected before dispatch");
		Check(!server.WaitForRequestCount(2, 800ms), "invalidated queued write never opens a second request");
		Check(server.RequestCount() == 1, "generation invalidation does not replay or dispatch the write");
		transport.Shutdown();
	}

	void TestPendingQueueIsBounded()
	{
		LocalServer server(Response(200, "OK", "{}"), true, true);
		std::promise<HttpResult> promise;
		auto result = promise.get_future();
		HttpTransport transport(1);
		const auto first = transport.Submit(MakeRequest(server.BaseUrl()),
			[&](HttpResult value) { promise.set_value(std::move(value)); });
		Check(first.queued, "one request fits a one-entry pending bound");
		Check(server.WaitForRequest(), "first bounded request becomes active");
		std::atomic<bool> called{};
		const auto second = transport.Submit(MakeRequest(server.BaseUrl(), "correspondence.compose", true),
			[&](HttpResult) { called = true; });
		Check(!second.queued && second.errorCode == ErrorCode::queueFull,
			"pending bound rejects a write synchronously before queue insertion");
		server.Release();
		const auto response = AwaitResult(result);
		Check(response.outcome == Outcome::completed, "accepted request completes under the bound");
		Check(!server.WaitForRequestCount(2, 800ms) && server.RequestCount() == 1,
			"queue-full write never reaches the network");
		Check(!called.load(), "queue-full write rejection has no callback");
		transport.Shutdown();
	}

	void TestShutdownMakesDispatchedWriteUnknown()
	{
		LocalServer server(Response(200, "OK", "{}"), true);
		std::promise<HttpResult> promise;
		auto result = promise.get_future();
		HttpTransport transport;
		const auto submitted = transport.Submit(MakeRequest(server.BaseUrl(), "pcv.arm", true),
			[&](HttpResult value) { promise.set_value(std::move(value)); });
		Check(submitted.queued, "shutdown write queues");
		Check(server.WaitForRequest(), "fixture observes dispatched write before shutdown");
		std::thread release([&server] {
			std::this_thread::sleep_for(100ms);
			server.Release();
		});
		transport.Shutdown();
		release.join();
		const auto response = AwaitResult(result);
		Check(response.outcome == Outcome::unknown && response.errorCode == ErrorCode::cancelled,
			"shutdown conservatively marks a dispatched write unknown");
		Check(server.RequestCount() == 1, "shutdown never replays the write");
	}

	void TestAbsoluteDeadlineStopsSlowDrip()
	{
		LocalServer server(Response(200, "OK", "01234567890123456789"), true, false, 1, 1s);
		std::promise<HttpResult> promise;
		auto result = promise.get_future();
		HttpTransport transport;
		const auto submitted = transport.Submit(MakeRequest(server.BaseUrl(), "pcv.arm", true),
			[&](HttpResult value) { promise.set_value(std::move(value)); });
		Check(submitted.queued, "timeout test write queues");
		Check(server.WaitForRequest(), "slow-drip fixture observes dispatched write");
		const auto start = std::chrono::steady_clock::now();
		server.Release();
		const auto response = AwaitResult(result, 18s);
		const auto elapsed = std::chrono::steady_clock::now() - start;
		if (response.outcome != Outcome::unknown || response.errorCode != ErrorCode::timeout) {
			std::cerr << "slow-drip result outcome=" << static_cast<int>(response.outcome)
				<< " error=" << static_cast<int>(response.errorCode)
				<< " status=" << response.statusCode
				<< " body-bytes=" << response.body.size()
				<< " elapsed-ms=" << std::chrono::duration_cast<std::chrono::milliseconds>(elapsed).count() << '\n';
		}
		Check(response.outcome == Outcome::unknown && response.errorCode == ErrorCode::timeout,
			"absolute deadline classifies a slow-drip dispatched write as unknown");
		Check(elapsed < 17s, "slow-drip cannot extend the transaction indefinitely");
		Check(server.RequestCount() == 1, "timed-out write is never replayed");
		transport.Shutdown();
	}

	void TestAbsoluteDeadlineAndHeaderSizeBound()
	{
		const std::string slowHeaders = "HTTP/1.1 200 OK\r\nX-Pad: " + std::string(40, 'a') +
			"\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
		LocalServer slowHeaderServer(slowHeaders, true, false, 0, 0ms, 1, 250ms);
		std::promise<HttpResult> slowPromise;
		auto slowResult = slowPromise.get_future();
		HttpTransport slowTransport;
		const auto slowSubmit = slowTransport.Submit(MakeRequest(slowHeaderServer.BaseUrl(), "pcv.arm", true),
			[&](HttpResult value) { slowPromise.set_value(std::move(value)); });
		Check(slowSubmit.queued, "slow-header test write queues");
		Check(slowHeaderServer.WaitForRequest(), "slow-header fixture observes dispatched write");
		const auto start = std::chrono::steady_clock::now();
		slowHeaderServer.Release();
		const auto slow = AwaitResult(slowResult, 18s);
		const auto elapsed = std::chrono::steady_clock::now() - start;
		Check(slow.outcome == Outcome::unknown && slow.errorCode == ErrorCode::timeout,
			"incomplete response headers time out within the transaction deadline");
		Check(elapsed < 17s, "slow response headers cannot hold the worker indefinitely");
		slowTransport.Shutdown();

		const std::string hugeHeaders = "HTTP/1.1 200 OK\r\nX-Pad: " + std::string(70'000, 'a') +
			"\r\nContent-Length: 0\r\nConnection: close\r\n\r\n";
		LocalServer hugeHeaderServer(hugeHeaders);
		std::promise<HttpResult> hugePromise;
		auto hugeResult = hugePromise.get_future();
		HttpTransport hugeTransport;
		const auto hugeSubmit = hugeTransport.Submit(MakeRequest(hugeHeaderServer.BaseUrl(), "pcv.arm", true),
			[&](HttpResult value) { hugePromise.set_value(std::move(value)); });
		Check(hugeSubmit.queued, "large-header test write queues");
		const auto huge = AwaitResult(hugeResult);
		Check(huge.outcome == Outcome::unknown && huge.errorCode == ErrorCode::transportFailure,
			"oversized response headers fail before the request can be treated as complete");
		hugeTransport.Shutdown();
	}
}

int main()
{
	TestFixedPostAndRedirectPolicy();
	TestRejectsUnsafeRequestInputsBeforeNetwork();
	TestInstallationPathPrefixIsPreserved();
	TestUnauthenticatedBootstrapOmitsAuthorization();
	TestResponseLimit();
	TestCloseCancelsReadsButPreservesAcceptedWrites();
	TestGenerationInvalidationCancelsQueuedWrites();
	TestPendingQueueIsBounded();
	TestShutdownMakesDispatchedWriteUnknown();
	TestAbsoluteDeadlineStopsSlowDrip();
	TestAbsoluteDeadlineAndHeaderSizeBound();
	std::cout << "http transport tests passed\n";
	return 0;
}
