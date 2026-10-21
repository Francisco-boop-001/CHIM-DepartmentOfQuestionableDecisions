#include "Contract.h"
#include "HttpTransport.h"
#include "NativePolicy.h"
#include "PcvCompanionPolicy.h"

#include <RE/Skyrim.h>
#include <RE/B/BSInputDeviceManager.h>
#include <RE/B/ButtonEvent.h>
#include <RE/I/InputEvent.h>
#include <RE/U/UI.h>
#include <SKSE/API.h>
#include <SKSE/InputMap.h>
#include <SKSE/Interfaces.h>
#include <SKSE/SKSE.h>

#include <Windows.h>
#include <objbase.h>

#ifdef GetObject
#undef GetObject
#endif

#include "PrismaUI_API.h"

#include <algorithm>
#include <array>
#include <atomic>
#include <chrono>
#include <cstdint>
#include <functional>
#include <iterator>
#include <limits>
#include <memory>
#include <optional>
#include <stdexcept>
#include <string>
#include <string_view>
#include <unordered_map>
#include <unordered_set>
#include <utility>
#include <vector>
#include <cstring>

using namespace REL::literals;

namespace
{
	constexpr std::string_view kPluginName = "WorldOfDramaLlama";
	constexpr std::string_view kViewPath = "WorldOfDramaLlama/index.html";
	constexpr std::string_view kPapyrusClass = "WorldOfDramaLlamaNative";

	std::string NewUuid()
	{
		GUID guid{};
		if (FAILED(CoCreateGuid(&guid))) {
			return {};
		}
		wchar_t wide[40]{};
		if (StringFromGUID2(guid, wide, static_cast<int>(std::size(wide))) != 39) {
			return {};
		}
		std::string value;
		value.reserve(36);
		for (int i = 1; i < 37; ++i) {
			auto ch = wide[i];
			if (ch >= L'A' && ch <= L'F') {
				ch = static_cast<wchar_t>(ch - L'A' + L'a');
			}
			value.push_back(static_cast<char>(ch));
		}
		return value;
	}

	std::optional<std::wstring> GetPluginIniPath()
	{
		const auto module = GetModuleHandleW(L"WorldOfDramaLlama.dll");
		if (!module) {
			return std::nullopt;
		}
		std::wstring path(MAX_PATH, L'\0');
		const auto length = GetModuleFileNameW(module, path.data(), static_cast<DWORD>(path.size()));
		if (length == 0 || length >= path.size()) {
			return std::nullopt;
		}
		path.resize(length);
		const auto separator = path.find_last_of(L"\\/");
		if (separator == std::wstring::npos) {
			return std::nullopt;
		}
		path.resize(separator + 1);
		path += L"WorldOfDramaLlama.ini";
		return path;
	}

	struct BridgeConfiguration
	{
		std::wstring serverBase;
		std::wstring pairToken;
	};

	BridgeConfiguration ReadBridgeConfiguration()
	{
		BridgeConfiguration result;
		const auto path = GetPluginIniPath();
		if (!path) return result;

		std::array<wchar_t, 2049> serverBase{};
		const auto baseLength = GetPrivateProfileStringW(L"Server", L"BaseUrl", L"", serverBase.data(),
			static_cast<DWORD>(serverBase.size()), path->c_str());
		if (baseLength < serverBase.size() - 1) {
			result.serverBase.assign(serverBase.data(), baseLength);
		}

		std::array<wchar_t, 513> pairToken{};
		const auto tokenLength = GetPrivateProfileStringW(L"Server", L"PairToken", L"", pairToken.data(),
			static_cast<DWORD>(pairToken.size()), path->c_str());
		if (tokenLength < pairToken.size() - 1) {
			result.pairToken.assign(pairToken.data(), tokenLength);
		}
		SecureZeroMemory(pairToken.data(), pairToken.size() * sizeof(wchar_t));
		return result;
	}

	const char* CommandName(dql::policy::NavigationCommand command)
	{
		using Command = dql::policy::NavigationCommand;
		switch (command) {
		case Command::up: return "up";
		case Command::down: return "down";
		case Command::left: return "left";
		case Command::right: return "right";
		case Command::next: return "next";
		case Command::previous: return "previous";
		case Command::activate: return "activate";
		case Command::back: return "back";
		}
		return "";
	}

	bool IsCanonicalNpcActorKey(std::string_view value) noexcept
	{
		return value.size() > 4 && value.size() <= 128 && value.starts_with("npc:") && value[4] != '0' &&
			std::all_of(value.begin() + 4, value.end(), [](char ch) { return ch >= '0' && ch <= '9'; });
	}

	template <std::size_t N>
	bool CopyFixed(char (&destination)[N], std::string_view value)
	{
		if (value.empty() || value.size() >= N || value.find('\0') != std::string_view::npos) return false;
		std::memcpy(destination, value.data(), value.size());
		destination[value.size()] = '\0';
		return true;
	}

	template <std::size_t N>
	bool CopyFixedAscii(char (&destination)[N], std::wstring_view value)
	{
		if (value.empty() || value.size() >= N) return false;
		for (std::size_t i = 0; i < value.size(); ++i) {
			if (value[i] <= 0 || value[i] > 0x7F) return false;
			destination[i] = static_cast<char>(value[i]);
		}
		destination[value.size()] = '\0';
		return true;
	}

	void WipeString(std::string& value) noexcept
	{
		if (!value.empty()) SecureZeroMemory(value.data(), value.size());
		value.clear();
	}

	void WipeJsonString(dql::contract::Json& value, std::string_view key) noexcept
	{
		if (!value.is_object() || !value.contains(std::string(key)) || !value[std::string(key)].is_string()) return;
		auto& text = value[std::string(key)].get_ref<std::string&>();
		WipeString(text);
	}

	void WipeEnvelopeText(dql::contract::Envelope& request) noexcept
	{
		WipeJsonString(request.payload, "text");
	}

	class StringWiper final
	{
	public:
		explicit StringWiper(std::string& value) : value_(value) {}
		~StringWiper() { WipeString(value_); }
		StringWiper(const StringWiper&) = delete;
		StringWiper& operator=(const StringWiper&) = delete;

	private:
		std::string& value_;
	};

	class EnvelopeTextWiper final
	{
	public:
		explicit EnvelopeTextWiper(dql::contract::Envelope& request) : request_(request) {}
		~EnvelopeTextWiper() { WipeEnvelopeText(request_); }
		EnvelopeTextWiper(const EnvelopeTextWiper&) = delete;
		EnvelopeTextWiper& operator=(const EnvelopeTextWiper&) = delete;

	private:
		dql::contract::Envelope& request_;
	};

	class ActorArrayCallback final : public RE::BSScript::IStackCallbackFunctor
	{
	public:
		using Completion = std::function<void(RE::BSScript::Variable)>;

		explicit ActorArrayCallback(Completion completion) : _completion(std::move(completion)) {}

		void operator()(RE::BSScript::Variable result) override
		{
			if (_completion) _completion(std::move(result));
		}

		void SetObject(const RE::BSTSmartPointer<RE::BSScript::Object>&) override {}

	private:
		Completion _completion;
	};

	class StockIniLease final
	{
	public:
		StockIniLease() = default;
		StockIniLease(HANDLE handle, std::string contents) : handle_(handle), contents_(std::move(contents)) {}
		~StockIniLease() { Reset(); }
		StockIniLease(const StockIniLease&) = delete;
		StockIniLease& operator=(const StockIniLease&) = delete;
		StockIniLease(StockIniLease&& other) noexcept :
			handle_(std::exchange(other.handle_, INVALID_HANDLE_VALUE)), contents_(std::move(other.contents_)) {}
		StockIniLease& operator=(StockIniLease&& other) noexcept
		{
			if (this != &other) {
				Reset();
				handle_ = std::exchange(other.handle_, INVALID_HANDLE_VALUE);
				contents_ = std::move(other.contents_);
			}
			return *this;
		}
		[[nodiscard]] std::string_view contents() const noexcept { return contents_; }
		[[nodiscard]] explicit operator bool() const noexcept { return handle_ != INVALID_HANDLE_VALUE; }

	private:
		void Reset() noexcept
		{
			if (handle_ != INVALID_HANDLE_VALUE) CloseHandle(std::exchange(handle_, INVALID_HANDLE_VALUE));
			if (!contents_.empty()) SecureZeroMemory(contents_.data(), contents_.size());
			contents_.clear();
		}

		HANDLE handle_{ INVALID_HANDLE_VALUE };
		std::string contents_;
	};

	std::optional<std::wstring> GetCurrentDirectorySnapshot()
	{
		std::array<wchar_t, 32768> current{};
		const auto length = GetCurrentDirectoryW(static_cast<DWORD>(current.size()), current.data());
		if (length == 0 || length >= current.size()) return std::nullopt;
		return std::wstring(current.data(), length);
	}

	std::optional<std::wstring> GetStockIniPath(std::wstring_view currentDirectory)
	{
		if (currentDirectory.empty()) return std::nullopt;
		std::wstring path(currentDirectory);
		if (path.back() != L'\\' && path.back() != L'/') path.push_back(L'\\');
		path += L"Data\\SKSE\\Plugins\\AIAgent.ini";
		return path;
	}

	std::optional<StockIniLease> OpenStockIniReadOnly(std::wstring_view path)
	{
		const std::wstring ownedPath(path);
		const HANDLE file = CreateFileW(ownedPath.c_str(), GENERIC_READ, FILE_SHARE_READ, nullptr, OPEN_EXISTING,
			FILE_ATTRIBUTE_NORMAL | FILE_FLAG_SEQUENTIAL_SCAN, nullptr);
		if (file == INVALID_HANDLE_VALUE) return std::nullopt;
		LARGE_INTEGER size{};
		if (!GetFileSizeEx(file, &size) || size.QuadPart < 0 || size.QuadPart > 16 * 1024) {
			CloseHandle(file);
			return std::nullopt;
		}
		std::string contents(static_cast<std::size_t>(size.QuadPart), '\0');
		std::size_t offset = 0;
		while (offset < contents.size()) {
			DWORD received{};
			const auto remaining = static_cast<DWORD>(std::min<std::size_t>(contents.size() - offset, MAXDWORD));
			if (!ReadFile(file, contents.data() + offset, remaining, &received, nullptr) || received == 0) {
				SecureZeroMemory(contents.data(), contents.size());
				CloseHandle(file);
				return std::nullopt;
			}
			offset += received;
		}
		return StockIniLease(file, std::move(contents));
	}

	class NativeShell final : public RE::BSTEventSink<RE::InputEvent*>
	{
	public:
		void OnPostLoad()
		{
			LoadOpenKey();
			LoadBridgeConfiguration();
			CaptureStockIniAtStartup();
			if (!prisma_) {
				prisma_ = static_cast<PRISMA_UI_API::IVPrismaUI1*>(PRISMA_UI_API::RequestPluginAPI());
			}
			if (prisma_ && !inputRegistered_) {
				if (auto* input = RE::BSInputDeviceManager::GetSingleton()) {
					input->AddEventSink(this);
					inputRegistered_ = true;
				}
			}
		}

		void OnPreLoadGame()
		{
			AdvanceGeneration();
			ClearInventorySnapshot();
			ClearManagedRoster();
			DestroyView();
		}

		void OnNewGame()
		{
			AdvanceGeneration();
			ClearInventorySnapshot();
			ClearManagedRoster();
			DestroyView();
		}

		void OnRevert()
		{
			AdvanceGeneration();
			ClearInventorySnapshot();
			ClearManagedRoster();
			DestroyView();
		}

		bool SetOpenKey(std::int32_t keyCode)
		{
			const bool supported = keyCode == -1 || (keyCode >= 0 && keyCode < SKSE::InputMap::kMacro_NumKeyboardKeys) ||
				(keyCode >= SKSE::InputMap::kMacro_GamepadOffset && keyCode < SKSE::InputMap::kMaxMacros);
			if (!supported) {
				return false;
			}
			openKeyCode_.store(keyCode, std::memory_order_relaxed);
			if (const auto* tasks = SKSE::GetTaskInterface()) {
				tasks->AddTask([keyCode] { PersistOpenKey(keyCode); });
			}
			return true;
		}

		void ToggleView()
		{
			if (IsOwnViewFocused()) {
				CloseView();
				return;
			}
			OpenView();
		}

		RE::BSEventNotifyControl ProcessEvent(RE::InputEvent* const* events, RE::BSTEventSource<RE::InputEvent*>*) override
		{
			if (!events || !*events || !prisma_) {
				return RE::BSEventNotifyControl::kContinue;
			}

			const bool ownFocused = IsOwnViewFocused();
			for (auto* event = *events; event; event = event->next) {
				if (event->GetEventType() != RE::INPUT_EVENT_TYPE::kButton) {
					continue;
				}
				const auto* button = event->AsButtonEvent();
				if (!button || !button->IsDown()) {
					continue;
				}
				dql::policy::InputSource source;
				switch (event->GetDevice()) {
				case RE::INPUT_DEVICE::kKeyboard: source = dql::policy::InputSource::keyboard; break;
				case RE::INPUT_DEVICE::kMouse: source = dql::policy::InputSource::mouse; break;
				case RE::INPUT_DEVICE::kGamepad: source = dql::policy::InputSource::gamepad; break;
				default: continue;
				}
				const auto inputCode = source == dql::policy::InputSource::gamepad ?
					SKSE::InputMap::GamepadMaskToKeycode(button->GetIDCode()) : button->GetIDCode();
				const auto normalized = dql::policy::NormalizeInputMacro(source, inputCode);
				if (!normalized) {
					continue;
				}
				const auto keyCode = *normalized;

				if (ownFocused && source == dql::policy::InputSource::keyboard && keyCode == 1) {
					CloseView();
					return RE::BSEventNotifyControl::kStop;
				}

				const auto assignedKey = openKeyCode_.load(std::memory_order_relaxed);
				if (dql::policy::ShouldToggleOpenHotkey(ownFocused, assignedKey >= 0 && keyCode == assignedKey, source) &&
					!prisma_->HasAnyActiveFocus()) {
					ToggleView();
					return RE::BSEventNotifyControl::kStop;
				}

				if (!ownFocused || source != dql::policy::InputSource::gamepad) {
					continue;
				}
				const auto command = dql::policy::MapGamepadButton(keyCode);
				if (!command) {
					continue;
				}
				if (*command == dql::policy::NavigationCommand::back) {
					CloseView();
				} else {
					SendNavigation(*command);
				}
				return RE::BSEventNotifyControl::kStop;
			}
			return RE::BSEventNotifyControl::kContinue;
		}

	private:
		static void RequestTrampoline(const char* argument)
		{
			if (!argument) {
				return;
			}
			const auto length = strnlen_s(argument, dql::contract::kMaxEnvelopeBytes + 1);
			if (length == 0 || length > dql::contract::kMaxEnvelopeBytes) {
				return;
			}
			auto raw = std::string(argument, length);
			EnqueueMain([raw = std::move(raw)]() mutable { Instance().HandleRequest(std::move(raw)); });
		}

		static void DomReady(PrismaView view)
		{
			EnqueueMain([view] { Instance().OnDomReady(view); });
		}

		template <class Task>
		static bool EnqueueMain(Task&& task)
		{
			if (const auto* tasks = SKSE::GetTaskInterface()) {
				tasks->AddTask(std::forward<Task>(task));
				return true;
			}
			return false;
		}

		static NativeShell& Instance()
		{
			// SKSE plugins are process-lifetime here; avoid joining the HTTP worker from static destruction under loader lock.
			static NativeShell* shell = new NativeShell();
			return *shell;
		}

		bool IsOwnViewValid() const
		{
			return prisma_ && view_ != 0 && prisma_->IsValid(view_);
		}

		bool IsOwnViewFocused() const
		{
			return IsOwnViewValid() && !prisma_->IsHidden(view_) && prisma_->HasFocus(view_);
		}

		void AdvanceGeneration()
		{
			if (!viewToken_.empty()) {
				transport_.CancelView(viewToken_, std::to_string(generation_), true);
			}
			if (generation_ == std::numeric_limits<std::uint64_t>::max()) {
				generation_ = 1;
			} else {
				++generation_;
			}
			viewToken_.clear();
			ClearServerAccess();
		}

		void ClearScopeToken()
		{
			if (!scopeToken_.empty()) SecureZeroMemory(scopeToken_.data(), scopeToken_.size());
			scopeToken_.clear();
		}

		void ClearCompanionOperations()
		{
			for (auto& [_, operation] : companionOperations_) {
				WipeString(operation.viewToken);
				WipeString(operation.nativeGeneration);
				WipeString(operation.serverContext);
			}
			companionOperations_.clear();
		}

		void SetSendIdentity(std::string_view scopeToken)
		{
			if (scopeToken.empty()) return;
			if (!scopeToken_.empty() && scopeToken_ != scopeToken) {
				ClearCompanionOperations();
			}
			if (scopeToken_ != scopeToken) {
				ClearScopeToken();
				scopeToken_.assign(scopeToken);
			}
		}

		void ClearServerAccess()
		{
			serverContext_.clear();
			serverAuthenticated_ = false;
			serverAccessPcv_ = false;
			serverAccessInspector_ = false;
			serverAccessCompose_ = false;
			serverAccessAuthorDetail_ = false;
			serverCapabilityPcv_ = false;
			serverCapabilityMp_ = false;
			serverCapabilitySas_ = false;
			serverCapabilityCorrespondence_ = false;
		}

		void LoadBridgeConfiguration()
		{
			auto config = ReadBridgeConfiguration();
			const bool pairChanged = config.serverBase != serverBase_ || config.pairToken != pairToken_;
			if (pairChanged) {
				pairIdentity_ = pairIdentity_ == std::numeric_limits<std::uint64_t>::max() ? 1 : pairIdentity_ + 1;
				ClearServerAccess();
			}
			if (!serverBase_.empty()) SecureZeroMemory(serverBase_.data(), serverBase_.size() * sizeof(wchar_t));
			if (!pairToken_.empty()) SecureZeroMemory(pairToken_.data(), pairToken_.size() * sizeof(wchar_t));
			serverBase_ = std::move(config.serverBase);
			pairToken_ = std::move(config.pairToken);
		}

		void CaptureStockIniAtStartup()
		{
			stockIniCaptured_ = false;
			stockIniSnapshot_.clear();
			stockIniPath_.clear();
			stockIniCwd_.clear();
			const auto cwd = GetCurrentDirectorySnapshot();
			const auto path = cwd ? GetStockIniPath(*cwd) : std::nullopt;
			const auto ini = path ? OpenStockIniReadOnly(*path) : std::nullopt;
			if (!cwd || !path || !ini) return;
			stockIniCwd_ = *cwd;
			stockIniPath_ = *path;
			stockIniSnapshot_.assign(ini->contents());
			stockIniCaptured_ = !stockIniSnapshot_.empty();
		}

		bool StockDestinationMatches() const
		{
			if (!stockIniCaptured_ || stockIniSnapshot_.empty()) return false;
			const auto cwd = GetCurrentDirectorySnapshot();
			if (!cwd || *cwd != stockIniCwd_) return false;
			const auto path = GetStockIniPath(*cwd);
			if (!path || *path != stockIniPath_) return false;
			const auto ini = OpenStockIniReadOnly(*path);
			if (!ini) return false;
			char facadeBase[2049]{};
			if (!CopyFixedAscii(facadeBase, serverBase_)) return false;
			const auto length = strnlen_s(facadeBase, sizeof(facadeBase));
			return length < sizeof(facadeBase) && dql::companion::CanUseStockDestination(
				stockIniSnapshot_, ini->contents(), std::string_view(facadeBase, length));
		}

		struct InventorySelection
		{
			std::string formId;
			std::int32_t availableQuantity{};
			std::uint64_t generation{};
		};

		struct ComposeResult
		{
			std::optional<dql::contract::Json> payload;
			std::string errorCode;
			std::string message;
		};

		struct PendingCompanionStage
		{
			dql::contract::Envelope request;
			std::string operationId;
			std::string actorKey;
			std::string actorName;
			std::uint32_t formId{};
			RE::ActorHandle actorHandle;
			dql::companion::ExpectedConfigIds expected;

			~PendingCompanionStage() { WipeEnvelopeText(request); }
		};

		struct CompanionOperation
		{
			std::string operationId;
			std::string state{ "unknown" };
			std::string phase{ "stage_uncertain" };
			std::string actorKey;
			std::optional<dql::companion::TargetIdentity> target;
			dql::companion::ExpectedConfigIds expected;
			RE::ActorHandle actorHandle;
			std::string viewToken;
			std::string nativeGeneration;
			std::string serverContext;
			std::int64_t expiresAt{};
			bool stageHttpSubmitted{};
			bool papyrusInvocationStarted{};
			std::uint64_t sequence{};
		};

		enum class CompanionHttpKind
		{
			stage,
			status,
			cancel
		};

		struct CompanionHttpContext
		{
			CompanionHttpKind kind{};
			dql::contract::Envelope uiRequest;
			dql::contract::Envelope serverRequest;
			std::string operationId;
		};

		void ClearInventorySnapshot()
		{
			inventorySelections_.clear();
			inventoryRevision_.clear();
		}

		void ClearManagedRoster()
		{
			pendingRosterRequest_.reset();
			pendingRosterPayload_ = dql::contract::Json::object();
			managedRoster_.clear();
			managedActorKeys_.clear();
			managedActorLabels_.clear();
			managedRosterNames_.clear();
			nativeRosterRevision_.clear();
			managedRosterLimited_ = false;
			managedRosterReady_ = false;
		}

		void BeginManagedRoster(const dql::contract::Envelope& request)
		{
			if (!request.payload.is_object() || !request.payload.empty()) {
				RespondError(request, "invalid_request", "The roster request has an invalid payload.");
				return;
			}
			if (pendingRosterRequest_) {
				RespondError(request, "busy", "A managed NPC roster read is already in progress.");
				return;
			}
			ClearManagedRoster();
			auto* vm = RE::SkyrimVM::GetSingleton();
			if (!vm || !vm->impl) {
				RespondError(request, "unavailable", "The CHIM-managed NPC roster is unavailable.");
				return;
			}
			pendingRosterRequest_ = request;
			const auto generation = generation_;
			RE::BSTSmartPointer<RE::BSScript::IStackCallbackFunctor> callback{ new ActorArrayCallback(
				[request, generation](RE::BSScript::Variable result) mutable {
					auto resultCopy = std::make_shared<RE::BSScript::Variable>(std::move(result));
					if (!EnqueueMain([request, generation, resultCopy = std::move(resultCopy)]() mutable {
						auto& shell = Instance();
						if (generation != shell.generation_) return;
						shell.OnManagedRoster(request, *resultCopy);
					})) {
						// The shared Papyrus result is released on this thread if the task queue is unavailable.
					}
				}) };
			const bool queued = vm->impl->DispatchStaticCall(
				RE::BSFixedString("AIAgentFunctions"), RE::BSFixedString("findAllNearbyAgents"),
				RE::MakeFunctionArguments(), callback);
			if (!queued) {
				pendingRosterRequest_.reset();
				RespondError(request, "unavailable", "CHIM did not return its managed NPC roster.");
			}
		}

		void OnManagedRoster(const dql::contract::Envelope& request, const RE::BSScript::Variable& result)
		{
			if (!pendingRosterRequest_ || pendingRosterRequest_->requestId != request.requestId ||
				request.nativeGeneration != std::to_string(generation_) || request.viewToken != viewToken_) {
				return;
			}
			if (!result.IsObjectArray()) {
				pendingRosterRequest_.reset();
				RespondError(request, "unavailable", "CHIM did not return its managed NPC roster.");
				return;
			}
			const auto array = result.GetArray();
			auto* vm = RE::SkyrimVM::GetSingleton();
			auto* handlePolicy = vm && vm->impl ? vm->impl->GetObjectHandlePolicy() : nullptr;
			if (!array || !handlePolicy) {
				pendingRosterRequest_.reset();
				RespondError(request, "unavailable", "The CHIM-managed NPC roster is unavailable.");
				return;
			}
			std::vector<RE::ActorHandle> handles;
			const auto count = std::min<std::size_t>(array->size(), dql::contract::kMaxArrayItems);
			handles.reserve(count);
			for (std::size_t i = 0; i < count; ++i) {
				const auto& actorValue = (*array)[static_cast<std::uint32_t>(i)];
				if (!actorValue.IsObject()) continue;
				const auto actorObject = actorValue.GetObject();
				if (!actorObject || !actorObject->IsValid()) continue;
				const auto vmHandle = actorObject->GetHandle();
				if (vmHandle == handlePolicy->EmptyHandle() || !handlePolicy->HandleIsType(RE::FormType::ActorCharacter, vmHandle)) continue;
				auto* actor = static_cast<RE::Actor*>(handlePolicy->GetObjectForHandle(RE::FormType::ActorCharacter, vmHandle));
				if (actor && !actor->IsDeleted() && !actor->IsDisabled()) handles.push_back(actor->GetHandle());
			}
			UpdateManagedRoster(handles, array->size() > dql::contract::kMaxArrayItems);
			if (!SubmitServerRequest(request, pendingRosterPayload_)) {
				pendingRosterRequest_.reset();
			}
		}

		void UpdateManagedRoster(const std::vector<RE::ActorHandle>& handles, bool sourceLimited)
		{
			struct ActorRecord
			{
				std::string label;
				RE::ActorHandle handle;
			};
			std::vector<ActorRecord> actors;
			actors.reserve(std::min<std::size_t>(handles.size(), dql::contract::kMaxArrayItems));
			for (const auto& handle : handles) {
				auto actorRef = handle.get();
				auto* actor = actorRef.get();
				if (!actor || actor->IsDeleted() || actor->IsDisabled()) continue;
				const char* rawLabel = actor->GetDisplayFullName();
				if (!rawLabel) rawLabel = actor->GetName();
				if (!rawLabel) continue;
				const auto rawLength = strnlen_s(rawLabel, 129);
				if (rawLength == 0 || rawLength > 128) {
					sourceLimited = true;
					continue;
				}
				auto label = dql::policy::CapUtf8(std::string_view(rawLabel, rawLength), 128);
				if (!label || label->empty() || label->size() != rawLength ||
					std::any_of(label->begin(), label->end(), [](unsigned char ch) { return ch < 0x20 || ch == 0x7F; })) {
					sourceLimited = true;
					continue;
				}
				actors.push_back({ std::move(*label), handle });
			}
			std::vector<std::string_view> labels;
			labels.reserve(actors.size());
			for (const auto& actor : actors) labels.push_back(actor.label);
			const auto names = dql::policy::CollectManagedNames(labels, 64);
			managedRoster_.clear();
			managedRosterNames_ = names.uniqueNames;
			for (const auto& name : names.uniqueNames) {
				const auto index = dql::policy::FindManagedActorIndex(labels, name);
				if (index) {
					managedRoster_.emplace(dql::policy::ManagedActorNameKey(name), actors[*index].handle);
				}
			}
			if (nativeRosterRevisionCounter_ == std::numeric_limits<std::uint64_t>::max()) nativeRosterRevisionCounter_ = 0;
			nativeRosterRevision_ = std::to_string(++nativeRosterRevisionCounter_);
			managedRosterLimited_ = sourceLimited || names.limited || !names.ambiguousNames.empty() || actors.size() > 64;
			pendingRosterPayload_ = {
				{ "managedNames", managedRosterNames_ },
				{ "nativeRosterRevision", nativeRosterRevision_ }
			};
		}

		std::optional<dql::policy::InstanceMetadata> InventoryExtraTypes(const RE::InventoryEntryData* entry) const
		{
			if (!entry || !entry->extraLists) {
				return dql::policy::InstanceMetadata::none;
			}
			bool hasMetadata = false;
			for (const auto* list : *entry->extraLists) {
				if (!list) {
					return dql::policy::InstanceMetadata::unsafe;
				}
				for (const auto& extra : *list) {
					if (extra.GetType() != RE::ExtraDataType::kCount) {
						return dql::policy::InstanceMetadata::unsafe;
					}
					hasMetadata = true;
				}
			}
			return hasMetadata ? dql::policy::InstanceMetadata::countOnly : dql::policy::InstanceMetadata::none;
		}

		dql::contract::Json BuildInventorySnapshot()
		{
			auto* player = RE::PlayerCharacter::GetSingleton();
			if (!player) {
				throw std::runtime_error("Player inventory is unavailable.");
			}
			if (inventoryRevisionCounter_ == std::numeric_limits<std::uint64_t>::max()) {
				inventoryRevisionCounter_ = 0;
			}
			inventoryRevision_ = std::to_string(++inventoryRevisionCounter_);
			inventorySelections_.clear();
			dql::contract::Json items = dql::contract::Json::array();
			const auto inventory = player->GetInventory();
			for (const auto& [form, row] : inventory) {
				if (!form || row.first <= 0 || form->IsGold() || form->IsDeleted() || form->IsDynamicForm() || !form->IsInventoryObject()) {
					continue;
				}
				const auto* file = form->GetFile(0);
				if (!file) {
					continue;
				}
				auto formId = dql::policy::CanonicalBaseFormId(file->GetFilename(), form->GetLocalFormID(), form->IsDynamicForm());
				if (!formId) {
					continue;
				}
				const auto* entry = row.second.get();
				const auto extraTypes = InventoryExtraTypes(entry);
				dql::policy::InventoryTraits traits{
					.count = row.first,
					.stableForm = !form->IsDynamicForm(),
					.inventoryObject = form->IsInventoryObject(),
					.dynamicForm = form->IsDynamicForm(),
					.instanceMetadata = *extraTypes,
					.questObject = entry && entry->IsQuestObject(),
					.equipped = entry && entry->IsWorn(),
					.enchanted = entry && entry->IsEnchanted(),
					.favorited = entry && entry->IsFavorited(),
					.poisoned = entry && entry->IsPoisoned(),
					.leveled = entry && entry->IsLeveled()
				};
				if (!dql::policy::IsTransferableStack(traits)) {
					continue;
				}
				const char* rawLabel = form->GetName();
				if (!rawLabel) {
					continue;
				}
				auto label = dql::policy::CapUtf8(rawLabel, 128);
				if (!label || label->empty()) {
					continue;
				}
				if (items.size() >= dql::contract::kMaxArrayItems) {
					continue;
				}
				std::string itemKey = NewUuid();
				if (!dql::policy::IsUuid(itemKey) || inventorySelections_.contains(itemKey)) {
					continue;
				}
				inventorySelections_.emplace(itemKey, InventorySelection{ *formId, row.first, generation_ });
				items.push_back({ { "itemKey", itemKey }, { "label", *label }, { "availableQuantity", row.first } });
			}
			return {
				{ "inventoryRevision", inventoryRevision_ },
				{ "items", std::move(items) },
				{ "gold", { { "available", std::max<std::int32_t>(0, player->GetGoldAmount()) } } }
			};
		}

		static bool HasExactProperties(const dql::contract::Json& value,
			std::initializer_list<std::string_view> required,
			std::initializer_list<std::string_view> optional = {})
		{
			if (!value.is_object() || value.size() < required.size() || value.size() > required.size() + optional.size()) {
				return false;
			}
			for (const auto key : required) {
				if (!value.contains(std::string(key))) return false;
			}
			for (const auto& [key, _] : value.items()) {
				const bool isRequired = std::find(required.begin(), required.end(), key) != required.end();
				const bool isOptional = std::find(optional.begin(), optional.end(), key) != optional.end();
				if (!isRequired && !isOptional) return false;
			}
			return true;
		}

		static bool ReadNonnegativeInteger(const dql::contract::Json& value, std::int64_t& result)
		{
			if (!value.is_number_integer()) return false;
			if (value.is_number_unsigned()) {
				const auto unsignedValue = value.get<std::uint64_t>();
				if (unsignedValue > static_cast<std::uint64_t>((std::numeric_limits<std::int64_t>::max)())) return false;
				result = static_cast<std::int64_t>(unsignedValue);
			} else {
				result = value.get<std::int64_t>();
			}
			return result >= 0;
		}

		std::optional<std::int32_t> CurrentSafeCount(std::string_view formId, RE::PlayerCharacter* player) const
		{
			if (!player || formId.empty()) return std::nullopt;
			for (const auto& [form, row] : player->GetInventory()) {
				if (!form || row.first <= 0 || form->IsGold() || form->IsDeleted() || form->IsDynamicForm() ||
					!form->IsInventoryObject()) {
					continue;
				}
			const auto* file = form->GetFile(0);
				if (!file) continue;
				auto canonical = dql::policy::CanonicalBaseFormId(
					file->GetFilename(), form->GetLocalFormID(), form->IsDynamicForm());
				if (!canonical || *canonical != formId) continue;
				const auto* entry = row.second.get();
				const auto extraTypes = InventoryExtraTypes(entry);
				if (!extraTypes) return std::nullopt;
				dql::policy::InventoryTraits traits{
					.count = row.first,
					.stableForm = true,
					.inventoryObject = form->IsInventoryObject(),
					.dynamicForm = form->IsDynamicForm(),
					.instanceMetadata = *extraTypes,
					.questObject = entry && entry->IsQuestObject(),
					.equipped = entry && entry->IsWorn(),
					.enchanted = entry && entry->IsEnchanted(),
					.favorited = entry && entry->IsFavorited(),
					.poisoned = entry && entry->IsPoisoned(),
					.leveled = entry && entry->IsLeveled()
				};
				if (!dql::policy::IsTransferableStack(traits)) return std::nullopt;
				return row.first;
			}
			return std::nullopt;
		}

		ComposeResult PrepareComposePayload(const dql::contract::Envelope& request)
		{
			const auto& input = request.payload;
			if (!HasExactProperties(input,
				{ "operationId", "writerActorKey", "recipientActorKey", "body", "inventoryRevision", "attachments", "goldAmount" },
				{ "signatureActorKey" }) ||
				!input["operationId"].is_string() || !dql::policy::IsUuid(input["operationId"].get_ref<const std::string&>()) ||
				!input["writerActorKey"].is_string() || !input["recipientActorKey"].is_string() ||
				!input["body"].is_string() || !input["inventoryRevision"].is_string() ||
				!input["attachments"].is_array()) {
				return { std::nullopt, "invalid_request", "The correspondence request is invalid." };
			}
			const auto safeActorKey = [](const dql::contract::Json& value) {
				if (!value.is_string()) return false;
				const auto& text = value.get_ref<const std::string&>();
				return !text.empty() && text.size() <= 128 && dql::policy::IsSafeHeaderValue(text);
			};
			if (!safeActorKey(input["writerActorKey"]) || !safeActorKey(input["recipientActorKey"]) ||
				(input.contains("signatureActorKey") && !safeActorKey(input["signatureActorKey"]))) {
				return { std::nullopt, "invalid_request", "The correspondence participants are invalid." };
			}
			const auto& body = input["body"].get_ref<const std::string&>();
			if (!dql::policy::IsAllowedPlayerText(body)) {
				return { std::nullopt, "invalid_request", "The letter text is invalid or exceeds its limit." };
			}
			const auto& revision = input["inventoryRevision"].get_ref<const std::string&>();
			if (revision.empty() || revision.size() > 128 || revision != inventoryRevision_) {
				return { std::nullopt, "stale_revision", "Refresh the player inventory before sending." };
			}
			std::int64_t goldAmount = 0;
			if (!ReadNonnegativeInteger(input["goldAmount"], goldAmount)) {
				return { std::nullopt, "invalid_request", "The gold amount is invalid." };
			}
			if (input["attachments"].size() > 8) {
				return { std::nullopt, "invalid_request", "The attachment selection exceeds its limit." };
			}
			auto* player = RE::PlayerCharacter::GetSingleton();
			if (!player) {
				return { std::nullopt, "unavailable", "The player inventory is unavailable." };
			}
			std::vector<dql::policy::TransferLine> lines;
			std::vector<std::string> formIds;
			std::unordered_set<std::string> resolvedForms;
			lines.reserve(input["attachments"].size());
			formIds.reserve(input["attachments"].size());
			for (const auto& attachment : input["attachments"]) {
				if (!HasExactProperties(attachment, { "itemKey", "quantity" }) ||
					!attachment["itemKey"].is_string()) {
					return { std::nullopt, "invalid_request", "An attachment selection is invalid." };
				}
				const auto& itemKey = attachment["itemKey"].get_ref<const std::string&>();
				std::int64_t quantity = 0;
				if (!ReadNonnegativeInteger(attachment["quantity"], quantity) || quantity == 0) {
					return { std::nullopt, "invalid_request", "An attachment quantity is invalid." };
				}
				const auto selection = inventorySelections_.find(itemKey);
				if (selection == inventorySelections_.end() || selection->second.generation != generation_) {
					return { std::nullopt, "stale_revision", "Refresh the player inventory before sending." };
				}
				const auto currentCount = CurrentSafeCount(selection->second.formId, player);
				if (!currentCount) {
					return { std::nullopt, "stale_revision", "An attachment is no longer a safe transferable stack." };
				}
				const auto available = std::min<std::int64_t>(selection->second.availableQuantity, *currentCount);
				lines.push_back({ itemKey, quantity, available });
				if (!resolvedForms.insert(selection->second.formId).second) {
					return { std::nullopt, "invalid_request", "Duplicate base forms cannot be attached twice." };
				}
				formIds.push_back(selection->second.formId);
			}
			const auto availableGold = std::max<std::int32_t>(0, player->GetGoldAmount());
			if (!dql::policy::ValidateTransferLines(lines, goldAmount, availableGold)) {
				return { std::nullopt, "stale_revision", "The requested items or gold are no longer available." };
			}
			dql::contract::Json attachments = dql::contract::Json::array();
			for (std::size_t i = 0; i < formIds.size(); ++i) {
				attachments.push_back({ { "form_id", formIds[i] }, { "quantity", lines[i].requestedQuantity } });
			}
			dql::contract::Json normalized{
				{ "operationId", input["operationId"] },
				{ "writerActorKey", input["writerActorKey"] },
				{ "recipientActorKey", input["recipientActorKey"] },
				{ "body", body },
				{ "inventoryRevision", revision },
				{ "goldAmount", goldAmount },
				{ "attachments", std::move(attachments) }
			};
			if (input.contains("signatureActorKey")) normalized["signatureActorKey"] = input["signatureActorKey"];
			return { std::move(normalized), {}, {} };
		}

		void LoadOpenKey()
		{
			const auto path = GetPluginIniPath();
			if (!path) {
				openKeyCode_.store(-1, std::memory_order_relaxed);
				return;
			}
			const auto value = static_cast<std::int32_t>(GetPrivateProfileIntW(
				L"Input", L"OpenKeyCode", static_cast<UINT>(-1), path->c_str()));
			const bool supported = value == -1 || (value >= 0 && value < SKSE::InputMap::kMacro_NumKeyboardKeys) ||
				(value >= SKSE::InputMap::kMacro_GamepadOffset && value < SKSE::InputMap::kMaxMacros);
			const auto validValue = supported ? value : -1;
			openKeyCode_.store(validValue, std::memory_order_relaxed);
		}

		static void PersistOpenKey(std::int32_t keyCode)
		{
			if (const auto path = GetPluginIniPath()) {
				const auto value = std::to_wstring(keyCode);
				WritePrivateProfileStringW(L"Input", L"OpenKeyCode", value.c_str(), path->c_str());
			}
		}

		void OpenView()
		{
			if (!prisma_ || IsOwnViewFocused()) {
				return;
			}
			if (prisma_->HasAnyActiveFocus()) {
				return;
			}
			auto* ui = RE::UI::GetSingleton();
			const bool uiAvailable = ui != nullptr;
			const bool mainOrLoadingMenu = uiAvailable && (ui->IsApplicationMenuOpen() ||
				ui->IsMenuOpen("Main Menu") || ui->IsMenuOpen("Loading Menu"));
			if (!dql::policy::CanOpenView(uiAvailable, prisma_->HasAnyActiveFocus(),
				uiAvailable && ui->GameIsPaused(), uiAvailable && ui->IsModalMenuOpen(),
				uiAvailable && ui->IsMenuOpen("Console"), uiAvailable && ui->IsMenuOpen("Dialogue Menu"), mainOrLoadingMenu)) {
				return;
			}
			viewToken_ = NewUuid();
			if (viewToken_.empty()) {
				return;
			}
			ClearServerAccess();
			initializeState_.ResetSent();
			if (IsOwnViewValid()) {
				prisma_->Show(view_);
				if (!prisma_->Focus(view_, false, false)) {
					prisma_->Hide(view_);
					viewToken_.clear();
					return;
				}
				viewReadyToInitialize_ = true;
				SendInitialize();
				return;
			}
			initializeState_.Reset();
			viewReadyToInitialize_ = false;
			creatingView_ = true;
			const auto createdView = prisma_->CreateView(kViewPath.data(), &DomReady);
			view_ = createdView;
			creatingView_ = false;
			if (!IsOwnViewValid()) {
				view_ = 0;
				initializeState_.Reset();
				viewToken_.clear();
				return;
			}
			prisma_->RegisterJSListener(view_, "departmentRequest", &RequestTrampoline);
			prisma_->Show(view_);
			if (!prisma_->Focus(view_, false, false)) {
				creatingView_ = false;
				CloseView();
				return;
			}
			viewReadyToInitialize_ = true;
			if (initializeState_.IsCurrentDomReady(view_)) {
				SendInitialize();
			}
		}

		void OnDomReady(PrismaView view)
		{
			initializeState_.ObserveDomReady(view_, view, creatingView_);
			if (initializeState_.IsCurrentDomReady(view_)) {
				SendInitialize();
			}
		}

		void CloseView(bool notifyUI = true)
		{
			if (!viewToken_.empty()) {
				transport_.CancelView(viewToken_, std::to_string(generation_), false);
			}
			if (IsOwnViewValid()) {
				if (dql::policy::ShouldNotifyUiBeforeNativeClose(!notifyUI, IsOwnViewFocused())) {
					SendNavigation(dql::policy::NavigationCommand::back);
				}
				if (prisma_->HasFocus(view_)) {
					prisma_->Unfocus(view_);
				}
				prisma_->Hide(view_);
			}
			viewToken_.clear();
			ClearServerAccess();
			ClearInventorySnapshot();
			ClearManagedRoster();
			initializeState_.ResetSent();
			creatingView_ = false;
			viewReadyToInitialize_ = IsOwnViewValid();
		}

		void DestroyView()
		{
			if (!viewToken_.empty()) {
				transport_.CancelView(viewToken_, std::to_string(generation_), true);
			}
			if (IsOwnViewValid()) {
				if (prisma_->HasFocus(view_)) {
					prisma_->Unfocus(view_);
				}
				prisma_->Hide(view_);
				prisma_->Destroy(view_);
			}
			view_ = 0;
			viewToken_.clear();
			ClearServerAccess();
			viewReadyToInitialize_ = false;
			initializeState_.Reset();
			creatingView_ = false;
		}

		void SendInitialize()
		{
			if (!initializeState_.TryMarkSent(view_, viewReadyToInitialize_, IsOwnViewFocused(), !viewToken_.empty())) return;
			const auto payload = dql::contract::Json{
				{ "version", 1 }, { "viewToken", viewToken_ },
				{ "nativeGeneration", std::to_string(generation_) }, { "serverContext", nullptr }
			}.dump();
			prisma_->InteropCall(view_, "departmentInitialize", payload.c_str());
		}

		void SendNavigation(dql::policy::NavigationCommand command)
		{
			if (!IsOwnViewFocused() || viewToken_.empty()) {
				return;
			}
			const auto payload = dql::contract::Json{
				{ "version", 1 }, { "viewToken", viewToken_ },
				{ "nativeGeneration", std::to_string(generation_) }, { "command", CommandName(command) }
			}.dump();
			prisma_->InteropCall(view_, "departmentNavigate", payload.c_str());
		}

		static bool IsServerWriteOperation(std::string_view operation) noexcept
		{
			return operation == "pcv.arm" || operation == "pcv.end" || operation == "correspondence.compose";
		}

		bool IsCurrentRequest(const dql::contract::Envelope& request) const
		{
			return IsOwnViewValid() && request.viewToken == viewToken_ &&
				request.nativeGeneration == std::to_string(generation_) &&
				(request.operation == "bootstrap" ||
					(request.serverContext && *request.serverContext == serverContext_));
		}

		dql::policy::NativeAccessState AccessState() const noexcept
		{
			return {
				.serverContext = serverContext_,
				.authenticated = serverAuthenticated_,
				.compose = serverAccessCompose_,
				.correspondenceReady = serverCapabilityCorrespondence_,
				.pcv = serverAccessPcv_,
				.pcvReady = serverCapabilityPcv_
			};
		}

		bool ValidBootstrapData(const dql::contract::Json& data) const
		{
			if (!HasExactProperties(data, { "authenticated", "authRequired", "serverContext", "scopeToken", "capabilities", "access" }, { "perspectives" }) ||
				!data["authenticated"].is_boolean() || !data["authRequired"].is_boolean() ||
				!HasExactProperties(data["capabilities"], { "pcv", "mp", "sas", "correspondence" }) ||
				!HasExactProperties(data["access"], { "pcv", "inspector", "compose", "authorDetail" })) {
				return false;
			}
			for (const auto key : { "pcv", "mp", "sas", "correspondence" }) {
				if (!data["capabilities"][key].is_boolean()) return false;
			}
			for (const auto key : { "pcv", "inspector", "compose", "authorDetail" }) {
				if (!data["access"][key].is_boolean()) return false;
			}
			const bool authenticated = data["authenticated"].get<bool>();
			if (data["authRequired"].get<bool>() == authenticated) return false;
			if (authenticated) {
				if (!data["serverContext"].is_string() || !data["scopeToken"].is_string() ||
					!dql::policy::IsSafeHeaderValue(data["serverContext"].get_ref<const std::string&>()) ||
					data["serverContext"].get_ref<const std::string&>().empty() ||
					data["serverContext"].get_ref<const std::string&>().size() > 128 ||
					data["scopeToken"].get_ref<const std::string&>().size() != 43 ||
					!std::all_of(data["scopeToken"].get_ref<const std::string&>().begin(),
						data["scopeToken"].get_ref<const std::string&>().end(), [](unsigned char ch) {
							return (ch >= 'A' && ch <= 'Z') || (ch >= 'a' && ch <= 'z') ||
								(ch >= '0' && ch <= '9') || ch == '_' || ch == '-';
						})) {
					return false;
				}
				if (data.contains("perspectives") && !data["access"]["inspector"].get<bool>()) return false;
				return !(data["access"]["authorDetail"].get<bool>() && !data["access"]["inspector"].get<bool>());
			}
			if (!data["serverContext"].is_null() || !data["scopeToken"].is_null() || data.contains("perspectives")) return false;
			for (const auto key : { "pcv", "mp", "sas", "correspondence" }) {
				if (data["capabilities"][key].get<bool>()) return false;
			}
			for (const auto key : { "pcv", "inspector", "compose", "authorDetail" }) {
				if (data["access"][key].get<bool>()) return false;
			}
			return true;
		}

		void AdoptBootstrapData(const dql::contract::Json& data)
		{
			ClearServerAccess();
			if (!data["authenticated"].get<bool>()) {
				return;
			}
			serverAuthenticated_ = true;
			serverContext_ = data["serverContext"].get<std::string>();
			const auto scopeToken = data["scopeToken"].get<std::string>();
			SetSendIdentity(scopeToken);
			serverAccessPcv_ = data["access"]["pcv"].get<bool>();
			serverAccessInspector_ = data["access"]["inspector"].get<bool>();
			serverAccessCompose_ = data["access"]["compose"].get<bool>();
			serverAccessAuthorDetail_ = data["access"]["authorDetail"].get<bool>();
			serverCapabilityPcv_ = data["capabilities"]["pcv"].get<bool>();
			serverCapabilityMp_ = data["capabilities"]["mp"].get<bool>();
			serverCapabilitySas_ = data["capabilities"]["sas"].get<bool>();
			serverCapabilityCorrespondence_ = data["capabilities"]["correspondence"].get<bool>();
		}

		void RespondRaw(const dql::contract::Envelope& request, std::string_view raw)
		{
			if (!IsCurrentRequest(request) || raw.empty() || raw.size() > dql::contract::kMaxEnvelopeBytes) return;
			const std::string value(raw);
			prisma_->InteropCall(view_, "departmentReceive", value.c_str());
		}

		void RespondServerWriteState(const dql::contract::Envelope& request, std::string_view state)
		{
			if (!request.payload.is_object() || !request.payload.contains("operationId") ||
				!request.payload["operationId"].is_string()) {
				RespondError(request, "invalid_response", "The operation result could not be correlated.");
				return;
			}
			Respond(request, { { "operationId", request.payload["operationId"] }, { "state", state } });
		}

		bool SubmitServerRequest(const dql::contract::Envelope& request, dql::contract::Json payload)
		{
			if (!IsCurrentRequest(request)) return false;
			if (serverBase_.empty()) {
				if (request.operation == "bootstrap") ClearServerAccess();
				if (IsServerWriteOperation(request.operation)) {
					RespondPreDispatchError(request, "unavailable", "The Department server is not configured.");
				}
				else RespondError(request, "unavailable", "The Department server is not configured.");
				return false;
			}
			const dql::contract::Json envelope{
				{ "version", 1 }, { "requestId", request.requestId }, { "operation", request.operation },
				{ "context", {
					{ "viewToken", request.viewToken },
					{ "nativeGeneration", request.nativeGeneration },
					{ "serverContext", request.serverContext ? dql::contract::Json(*request.serverContext) : dql::contract::Json(nullptr) }
				} },
				{ "payload", std::move(payload) }
			};
			dql::http::HttpRequest httpRequest{
				.requestId = request.requestId,
				.viewToken = request.viewToken,
				.nativeGeneration = request.nativeGeneration,
				.operation = request.operation,
				.envelopeJson = envelope.dump(),
				.serverBase = serverBase_,
				.bearerToken = pairToken_,
				.isWrite = IsServerWriteOperation(request.operation)
			};
			const auto submitted = transport_.Submit(std::move(httpRequest), [request](dql::http::HttpResult result) mutable {
				auto resultCopy = std::make_shared<dql::http::HttpResult>(std::move(result));
				EnqueueMain([request, resultCopy = std::move(resultCopy)]() mutable {
					Instance().OnServerCompletion(request, std::move(*resultCopy));
				});
			});
			if (!submitted.queued) {
				if (request.operation == "bootstrap") ClearServerAccess();
				if (IsServerWriteOperation(request.operation)) {
					RespondPreDispatchError(request, submitted.errorCode == dql::http::ErrorCode::queueFull ? "busy" : "unavailable",
						"The Department server request could not be queued.");
				} else {
					RespondError(request, submitted.errorCode == dql::http::ErrorCode::queueFull ? "busy" : "unavailable",
						"The Department server request could not be queued.");
				}
				return false;
			}
			return true;
		}

		void OnServerCompletion(const dql::contract::Envelope& request, dql::http::HttpResult result)
		{
			if (!IsCurrentRequest(request)) return;
			if (result.outcome != dql::http::Outcome::completed) {
				if (request.operation == "bootstrap") ClearServerAccess();
				if (request.operation == "pcv.roster.read") pendingRosterRequest_.reset();
				if (IsServerWriteOperation(request.operation)) {
					RespondServerWriteState(request, result.outcome == dql::http::Outcome::rejected ? "rejected" : "unknown");
				} else {
					RespondError(request, result.errorCode == dql::http::ErrorCode::timeout ? "unavailable" : "unavailable",
						"The Department server did not return a complete response.");
				}
				return;
			}
			const auto parsed = dql::contract::ParseServerResponse(result.body, request);
			if (!parsed.value) {
				if (request.operation == "bootstrap") ClearServerAccess();
				if (request.operation == "pcv.roster.read") pendingRosterRequest_.reset();
				if (IsServerWriteOperation(request.operation)) RespondServerWriteState(request, "unknown");
				else RespondError(request, "invalid_response", "The Department server returned an invalid response.");
				return;
			}
			const auto& response = *parsed.value;
			const bool ok = response["ok"].get<bool>();
			if (result.statusCode < 200 || result.statusCode >= 300) {
				if (request.operation == "bootstrap") ClearServerAccess();
				if (request.operation == "pcv.roster.read") pendingRosterRequest_.reset();
				if (IsServerWriteOperation(request.operation) && ok) RespondServerWriteState(request, "unknown");
				else RespondRaw(request, result.body);
				return;
			}
			if (!ok) {
				const auto& errorCode = response["error"]["code"].get_ref<const std::string&>();
				if (errorCode == "unauthorized" || errorCode == "forbidden" || errorCode == "stale_context") ClearServerAccess();
				if (request.operation == "bootstrap") ClearServerAccess();
				if (request.operation == "pcv.roster.read") pendingRosterRequest_.reset();
				RespondRaw(request, result.body);
				return;
			}
			if (request.operation == "bootstrap") {
				if (!response["data"].is_object() || !ValidBootstrapData(response["data"])) {
					ClearServerAccess();
					RespondError(request, "invalid_response", "The Department server returned invalid access information.");
					return;
				}
				AdoptBootstrapData(response["data"]);
			}
			if (request.operation == "pcv.roster.read") {
				auto data = response["data"];
				if (!ValidateAndMergeRoster(data)) {
					pendingRosterRequest_.reset();
					RespondError(request, "stale_context", "The managed NPC roster changed. Refresh it before continuing.");
					return;
				}
				pendingRosterRequest_.reset();
				Respond(request, data);
				return;
			}
			RespondRaw(request, result.body);
		}

		bool ValidateAndMergeRoster(dql::contract::Json& data)
		{
			if (!HasExactProperties(data, { "revision", "state", "actors", "limited" }) ||
				!data["revision"].is_string() || data["revision"].get_ref<const std::string&>() != nativeRosterRevision_ ||
				!data["state"].is_string() || !data["actors"].is_array() || !data["limited"].is_boolean() ||
				data["actors"].size() > dql::contract::kMaxArrayItems) return false;
			const auto& state = data["state"].get_ref<const std::string&>();
			if (state != "ready" && state != "empty" && state != "missing" && state != "stale" &&
				state != "unavailable") return false;
			std::unordered_set<std::string> actorKeys;
			std::unordered_set<std::string> labels;
			std::unordered_map<std::string, RE::ActorHandle> managedActorKeys;
			std::unordered_map<std::string, std::string> managedActorLabels;
			const bool ready = data["state"].get_ref<const std::string&>() == "ready";
			for (const auto& actor : data["actors"]) {
				if (!HasExactProperties(actor, { "actorKey", "label", "eligibility", "reason" }) ||
					!actor["actorKey"].is_string() || !actor["label"].is_string() ||
					!actor["eligibility"].is_string() || !(actor["reason"].is_null() || actor["reason"].is_string())) return false;
				const auto& actorKey = actor["actorKey"].get_ref<const std::string&>();
				const auto& label = actor["label"].get_ref<const std::string&>();
				const auto& eligibility = actor["eligibility"].get_ref<const std::string&>();
				if (!IsCanonicalNpcActorKey(actorKey) || label.empty() || label.size() > 128 ||
					(eligibility != "eligible" && eligibility != "ineligible") ||
					(data["actors"].size() > 0 && actor["reason"].is_string() &&
						(actor["reason"].get_ref<const std::string&>().empty() || actor["reason"].get_ref<const std::string&>().size() > 64 ||
							!dql::policy::IsSafeHeaderValue(actor["reason"].get_ref<const std::string&>())))) return false;
				const auto folded = dql::policy::ManagedActorNameKey(label);
				if (!managedRoster_.contains(folded) || !actorKeys.insert(actorKey).second || !labels.insert(folded).second) return false;
				if (ready && eligibility == "eligible") {
					managedActorKeys.emplace(actorKey, managedRoster_.at(folded));
					managedActorLabels.emplace(actorKey, label);
				}
			}
			managedActorKeys_ = std::move(managedActorKeys);
			managedActorLabels_ = std::move(managedActorLabels);
			managedRosterReady_ = ready;
			data["limited"] = data["limited"].get<bool>() || managedRosterLimited_;
			return true;
		}

		std::optional<std::string> ReadActorName(RE::Actor* actor) const
		{
			if (!actor || actor->IsDeleted() || actor->IsDisabled() || actor->IsDead()) return std::nullopt;
			const char* rawName = actor->GetDisplayFullName();
			if (!rawName) rawName = actor->GetName();
			if (!rawName) return std::nullopt;
			const auto length = strnlen_s(rawName, 129);
			if (length == 0 || length > 128) return std::nullopt;
			auto name = dql::policy::CapUtf8(std::string_view(rawName, length), 128);
			if (!name || name->size() != length || std::any_of(name->begin(), name->end(), [](unsigned char ch) {
				return ch < 0x20 || ch == 0x7F;
			})) return std::nullopt;
			return name;
		}

		std::optional<RE::ActorHandle> ResolveFreshPcvActor(std::string_view actorKey,
			const RE::BSScript::Variable& result, dql::companion::TargetIdentity& target) const
		{
			if (!managedRosterReady_ || !IsCanonicalNpcActorKey(actorKey) || !managedActorKeys_.contains(std::string(actorKey))) {
				return std::nullopt;
			}
			const auto mappedActor = managedActorKeys_.find(std::string(actorKey));
			auto mappedRef = mappedActor->second.get();
			auto* mapped = mappedRef.get();
			if (!mapped || mapped->IsDeleted() || mapped->IsDisabled() || mapped->IsDead()) return std::nullopt;
			const auto expected = managedActorLabels_.find(std::string(actorKey));
			if (expected == managedActorLabels_.end() || !result.IsObjectArray()) return std::nullopt;
			const auto array = result.GetArray();
			auto* vm = RE::SkyrimVM::GetSingleton();
			auto* policy = vm && vm->impl ? vm->impl->GetObjectHandlePolicy() : nullptr;
			if (!array || !policy) return std::nullopt;

			dql::companion::FreshActorNameMatch freshMatch(expected->second, mapped);
			std::optional<RE::ActorHandle> matched;
			std::optional<std::string> matchedName;
			std::uint32_t matchedFormId{};
			const auto count = std::min<std::size_t>(array->size(), dql::contract::kMaxArrayItems);
			for (std::size_t i = 0; i < count; ++i) {
				const auto& value = (*array)[static_cast<std::uint32_t>(i)];
				if (!value.IsObject()) continue;
				const auto object = value.GetObject();
				if (!object || !object->IsValid()) continue;
				const auto handle = object->GetHandle();
				if (handle == policy->EmptyHandle() || !policy->HandleIsType(RE::FormType::ActorCharacter, handle)) continue;
				auto* actor = static_cast<RE::Actor*>(policy->GetObjectForHandle(RE::FormType::ActorCharacter, handle));
				const auto name = ReadActorName(actor);
				if (!name) continue;
				const bool nameMatches = freshMatch.Observe(actor, *name);
				if (actor != mapped || !nameMatches) continue;
				const auto formId = actor->GetFormID();
				if (formId == 0) return std::nullopt;
				matched = actor->GetHandle();
				matchedName = *name;
				matchedFormId = static_cast<std::uint32_t>(formId);
			}
			if (array->size() > dql::contract::kMaxArrayItems || !freshMatch.IsUniqueMappedActor() || !matched || !matchedName) return std::nullopt;
			target = { dql::companion::FormIdHex(matchedFormId), std::move(*matchedName) };
			return matched;
		}

		static dql::contract::Envelope CompanionUiRequest(const dql::contract::Envelope& source)
		{
			auto request = source;
			WipeEnvelopeText(request);
			request.payload = dql::contract::Json::object();
			return request;
		}

		void RespondCompanionState(const dql::contract::Envelope& request, std::string_view operationId,
			std::string_view state, const std::optional<dql::companion::ExpectedConfigIds>& expected,
			std::string_view phase)
		{
			dql::contract::Json data{
				{ "operationId", operationId }, { "state", state }
			};
			if (request.operation == "pcv.send.status") {
				const bool known = state != "unknown" && expected.has_value();
				data["expectedActiveConfigId"] = known && expected->active ? dql::contract::Json(*expected->active) : dql::contract::Json(nullptr);
				data["expectedPendingConfigId"] = known && expected->pending ? dql::contract::Json(*expected->pending) : dql::contract::Json(nullptr);
			}
			if (!phase.empty()) data["phase"] = phase.substr(0, 48);
			Respond(request, data);
		}

		bool HasCompanionOperationCapacity()
		{
			constexpr std::size_t kMaxTrackedOperations = 128;
			if (companionOperations_.size() < kMaxTrackedOperations) return true;
			const auto terminal = [](std::string_view state) {
				return state == "consumed" || state == "rejected" || state == "cancelled" || state == "expired";
			};
			const auto oldest = std::min_element(companionOperations_.begin(), companionOperations_.end(),
				[&](const auto& left, const auto& right) {
					const auto leftTerminal = terminal(left.second.state);
					const auto rightTerminal = terminal(right.second.state);
					if (leftTerminal != rightTerminal) return leftTerminal;
					return left.second.sequence < right.second.sequence;
				});
			if (oldest == companionOperations_.end() || !terminal(oldest->second.state)) return false;
			companionOperations_.erase(oldest);
			return true;
		}

		static std::int64_t CurrentUnixSeconds()
		{
			return std::chrono::duration_cast<std::chrono::seconds>(
				std::chrono::system_clock::now().time_since_epoch()).count();
		}

		bool SubmitCompanionHttp(CompanionHttpKind kind, const dql::contract::Envelope& source,
			std::string_view operationId, dql::contract::Json payload)
		{
			if (serverBase_.empty() || pairToken_.empty() || !source.serverContext || source.serverContext->empty()) return false;
			const auto requestId = NewUuid();
			if (requestId.empty()) return false;
			const char* operation = kind == CompanionHttpKind::stage ? "pcv.companion.stage" :
				kind == CompanionHttpKind::status ? "pcv.companion.status" : "pcv.companion.cancel";
			dql::contract::Envelope serverRequest{
				.requestId = requestId, .operation = operation, .viewToken = source.viewToken,
				.nativeGeneration = source.nativeGeneration, .serverContext = source.serverContext,
				.payload = dql::contract::Json::object()
			};
			dql::contract::Json envelope{
				{ "version", 1 }, { "requestId", requestId }, { "operation", operation },
				{ "context", { { "viewToken", source.viewToken },
					{ "nativeGeneration", source.nativeGeneration }, { "serverContext", *source.serverContext } } },
				{ "payload", std::move(payload) }
			};
			std::string body = envelope.dump();
			WipeJsonString(envelope["payload"], "text");
			dql::http::HttpRequest httpRequest{
				.requestId = requestId, .viewToken = source.viewToken,
				.nativeGeneration = source.nativeGeneration, .operation = operation,
				.envelopeJson = std::move(body), .serverBase = serverBase_, .bearerToken = pairToken_,
				.isWrite = kind != CompanionHttpKind::status
			};
			auto context = std::make_shared<CompanionHttpContext>(CompanionHttpContext{
				.kind = kind, .uiRequest = CompanionUiRequest(source), .serverRequest = std::move(serverRequest),
				.operationId = std::string(operationId)
			});
			const auto submitted = transport_.Submit(std::move(httpRequest), [context](dql::http::HttpResult result) mutable {
				auto resultCopy = std::make_shared<dql::http::HttpResult>(std::move(result));
				if (!EnqueueMain([context, resultCopy]() mutable {
					Instance().OnCompanionHttpCompletion(*context, std::move(*resultCopy));
				})) {
					WipeString(resultCopy->body);
				}
			});
			return submitted.queued;
		}

		std::optional<dql::contract::Json> ReadCompanionData(const CompanionHttpContext& context,
			const dql::http::HttpResult& result, std::string& errorCode) const
		{
			if (result.outcome != dql::http::Outcome::completed) {
				errorCode = "transport_uncertain";
				return std::nullopt;
			}
			auto parsed = dql::contract::ParseServerResponse(result.body, context.serverRequest);
			if (!parsed.value) {
				errorCode = "invalid_response";
				return std::nullopt;
			}
			auto response = std::move(*parsed.value);
			if (result.statusCode < 200 || result.statusCode >= 300 || !response["ok"].get<bool>()) {
				if (response.contains("data") && response["data"].is_object()) {
					WipeJsonString(response["data"], "token");
				}
				errorCode = response["ok"].get<bool>() ? "http_error" :
					response["error"]["code"].get<std::string>();
				return std::nullopt;
			}
			if (!response["data"].is_object()) {
				errorCode = "invalid_response";
				return std::nullopt;
			}
			auto data = std::move(response["data"]);
			if (context.kind != CompanionHttpKind::stage) WipeJsonString(data, "token");
			return data;
		}

		bool ResolveCurrentOperationActor(const CompanionOperation& operation, RE::Actor*& actor) const
		{
			actor = nullptr;
			if (!operation.target || !managedRosterReady_) return false;
			const auto found = managedActorKeys_.find(operation.actorKey);
			const auto label = managedActorLabels_.find(operation.actorKey);
			if (found == managedActorKeys_.end() || label == managedActorLabels_.end() ||
				!dql::companion::NamesMatchManagedActor(label->second, operation.target->name)) return false;
			auto expectedRef = operation.actorHandle.get();
			auto currentRef = found->second.get();
			auto* expectedActor = expectedRef.get();
			actor = currentRef.get();
			if (!actor || actor != expectedActor) return false;
			const auto name = ReadActorName(actor);
			const auto formId = actor->GetFormID();
			return name && dql::companion::NamesMatchManagedActor(*name, operation.target->name) && formId != 0 &&
				dql::companion::FormIdHex(static_cast<std::uint32_t>(formId)) == operation.target->formId;
		}

		void BeginPcvSend(dql::contract::Envelope request)
		{
			EnvelopeTextWiper wipeRequestText(request);
			auto& payload = request.payload;
			const bool hasActive = payload.is_object() && payload.contains("expectedActiveConfigId");
			const bool hasPending = payload.is_object() && payload.contains("expectedPendingConfigId");
			if (!HasExactProperties(payload, { "operationId", "actorKey", "text", "expectedActiveConfigId", "expectedPendingConfigId" }) ||
				!payload["operationId"].is_string() || !payload["actorKey"].is_string() || !payload["text"].is_string() ||
				!hasActive || !hasPending || !(payload["expectedActiveConfigId"].is_null() || payload["expectedActiveConfigId"].is_string()) ||
				!(payload["expectedPendingConfigId"].is_null() || payload["expectedPendingConfigId"].is_string())) {
				RespondPreDispatchError(request, "invalid_request", "The PCV send request is invalid.");
				return;
			}
			const auto operationId = payload["operationId"].get<std::string>();
			const auto actorKey = payload["actorKey"].get<std::string>();
			std::string text = payload["text"].get<std::string>();
			StringWiper wipeText(text);
			dql::companion::ExpectedConfigIds expected{
				.active = payload["expectedActiveConfigId"].is_string() ?
					std::optional(payload["expectedActiveConfigId"].get<std::string>()) : std::nullopt,
				.pending = payload["expectedPendingConfigId"].is_string() ?
					std::optional(payload["expectedPendingConfigId"].get<std::string>()) : std::nullopt
			};
			if (!dql::policy::IsUuid(operationId) || !IsCanonicalNpcActorKey(actorKey) ||
				(expected.active && !dql::policy::IsUuid(*expected.active)) ||
				(expected.pending && !dql::policy::IsUuid(*expected.pending)) || !dql::policy::IsAllowedPlayerText(text)) {
				RespondPreDispatchError(request, "invalid_request", "The PCV send request is invalid.");
				return;
			}
			if (const auto prior = companionOperations_.find(operationId); prior != companionOperations_.end()) {
				RespondCompanionState(request, operationId, prior->second.state, prior->second.expected, prior->second.phase);
				return;
			}
			if (!request.serverContext || !dql::policy::CanSendCompanionPcv(AccessState(), *request.serverContext)) {
				RespondPreDispatchError(request, "forbidden", "The current pairing cannot stage PCV input.");
				return;
			}
			if (!StockDestinationMatches()) {
				RespondPreDispatchError(request, "unavailable", "The stock CHIM destination is missing, changed, or does not match this Department server.");
				return;
			}
			if (!managedRosterReady_ || !managedActorKeys_.contains(actorKey) || !managedActorLabels_.contains(actorKey)) {
				RespondPreDispatchError(request, "stale_context", "The selected managed actor is no longer available.");
				return;
			}
			if (!HasCompanionOperationCapacity()) {
				RespondPreDispatchError(request, "busy", "Too many unresolved PCV operations are being tracked.");
				return;
			}

			auto pending = std::make_shared<PendingCompanionStage>();
			pending->request = std::move(request);
			pending->operationId = operationId;
			pending->actorKey = actorKey;
			pending->expected = std::move(expected);
			auto* vm = RE::SkyrimVM::GetSingleton();
			if (!vm || !vm->impl) {
				RespondPreDispatchError(pending->request, "unavailable", "The stock CHIM actor roster is unavailable.");
				return;
			}
			const auto generation = generation_;
			RE::BSTSmartPointer<RE::BSScript::IStackCallbackFunctor> callback{ new ActorArrayCallback(
				[pending, generation](RE::BSScript::Variable result) mutable {
					auto resultCopy = std::make_shared<RE::BSScript::Variable>(std::move(result));
					if (!EnqueueMain([pending, generation, resultCopy = std::move(resultCopy)]() mutable {
						auto& shell = Instance();
						if (generation != shell.generation_) return;
						shell.OnPcvFreshRoster(*pending, *resultCopy);
					})) {
						// The result and text-bearing request are released after the VM task queue rejects this callback.
					}
				}) };
			const bool queued = vm->impl->DispatchStaticCall(RE::BSFixedString("AIAgentFunctions"),
				RE::BSFixedString("findAllNearbyAgents"), RE::MakeFunctionArguments(), callback);
			if (!queued) RespondPreDispatchError(pending->request, "unavailable", "The stock CHIM actor roster could not be refreshed.");
		}

		void OnPcvFreshRoster(PendingCompanionStage& pending, const RE::BSScript::Variable& result)
		{
			if (!IsCurrentRequest(pending.request) || !pending.request.serverContext ||
				!dql::policy::CanSendCompanionPcv(AccessState(), *pending.request.serverContext)) return;
			dql::companion::TargetIdentity target;
			const auto actorHandle = ResolveFreshPcvActor(pending.actorKey, result, target);
			if (!actorHandle) {
				RespondPreDispatchError(pending.request, "stale_context", "The selected actor could not be matched to the fresh CHIM roster.");
				return;
			}
			if (!StockDestinationMatches()) {
				RespondPreDispatchError(pending.request, "unavailable", "The stock CHIM destination changed before staging.");
				return;
			}
			CompanionOperation operation{
				.operationId = pending.operationId, .state = "unknown", .phase = "stage_uncertain",
				.actorKey = pending.actorKey, .target = target, .expected = pending.expected,
				.actorHandle = *actorHandle, .viewToken = pending.request.viewToken,
				.nativeGeneration = pending.request.nativeGeneration,
				.serverContext = pending.request.serverContext.value_or(std::string{}),
				.sequence = ++companionSequenceCounter_
			};
			const auto [position, inserted] = companionOperations_.emplace(operation.operationId, std::move(operation));
			if (!inserted) {
				RespondCompanionState(pending.request, pending.operationId, position->second.state,
					position->second.expected, position->second.phase);
				return;
			}
			dql::contract::Json payload{
				{ "operationId", pending.operationId }, { "text", pending.request.payload["text"] },
				{ "target", { { "formId", target.formId }, { "name", target.name } } },
				{ "expectedActiveConfigId", pending.expected.active ? dql::contract::Json(*pending.expected.active) : dql::contract::Json(nullptr) },
				{ "expectedPendingConfigId", pending.expected.pending ? dql::contract::Json(*pending.expected.pending) : dql::contract::Json(nullptr) }
			};
			if (!SubmitCompanionHttp(CompanionHttpKind::stage, pending.request, pending.operationId, std::move(payload))) {
				companionOperations_.erase(pending.operationId);
				RespondPreDispatchError(pending.request, "busy", "The PCV stage request could not be queued.");
			} else if (auto submitted = companionOperations_.find(pending.operationId); submitted != companionOperations_.end()) {
				submitted->second.stageHttpSubmitted = true;
				submitted->second.phase = "stage_http_submitted";
			}
			WipeEnvelopeText(pending.request);
		}

		void BeginPcvSendStatus(const dql::contract::Envelope& request)
		{
			if (!HasExactProperties(request.payload, { "operationId" }) || !request.payload["operationId"].is_string()) {
				RespondError(request, "invalid_request", "The PCV send status request is invalid.");
				return;
			}
			const auto operationId = request.payload["operationId"].get<std::string>();
			if (!dql::policy::IsUuid(operationId)) {
				RespondError(request, "invalid_request", "The PCV send status request is invalid.");
				return;
			}
			if (!SubmitCompanionHttp(CompanionHttpKind::status, request, operationId,
				{ { "operationId", operationId } })) {
				RespondCompanionState(request, operationId, "unknown", std::nullopt, "status_uncertain");
			}
		}

		void BeginCompanionCancel(const CompanionHttpContext& parent)
		{
			auto operation = companionOperations_.find(parent.operationId);
			if (operation != companionOperations_.end()) operation->second.phase = "cancel_uncertain";
			if (!SubmitCompanionHttp(CompanionHttpKind::cancel, parent.uiRequest, parent.operationId,
				{ { "operationId", parent.operationId } })) {
				if (operation != companionOperations_.end()) {
					operation->second.state = "unknown";
					operation->second.phase = "cancel_uncertain";
					RespondCompanionState(parent.uiRequest, parent.operationId, "unknown", std::nullopt, "cancel_uncertain");
				}
			}
		}

		void OnCompanionHttpCompletion(const CompanionHttpContext& context, dql::http::HttpResult result)
		{
			StringWiper wipeBody(result.body);
			std::string errorCode;
			auto data = ReadCompanionData(context, result, errorCode);
			if (context.kind == CompanionHttpKind::stage) {
				if (!data || !dql::companion::IsFreshStageData(*data, context.operationId, CurrentUnixSeconds())) {
					if (data) WipeJsonString(*data, "token");
					if (auto operation = companionOperations_.find(context.operationId); operation != companionOperations_.end()) {
						operation->second.state = "unknown";
						operation->second.phase = "stage_reconcile";
					}
					if (!SubmitCompanionHttp(CompanionHttpKind::status, context.uiRequest, context.operationId,
						{ { "operationId", context.operationId } })) {
						RespondCompanionState(context.uiRequest, context.operationId, "unknown", std::nullopt, "stage_uncertain");
					}
					return;
				}
				auto token = data->at("token").get<std::string>();
				WipeJsonString(*data, "token");
				StringWiper wipeToken(token);
				auto operation = companionOperations_.find(context.operationId);
				if (operation == companionOperations_.end()) {
					BeginCompanionCancel(context);
					return;
				}
				operation->second.state = "prepared";
				operation->second.expiresAt = data->at("expiresAt").get<std::int64_t>();
				operation->second.phase = "stage_prepared";
				RE::Actor* actor = nullptr;
				const bool current = IsCurrentRequest(context.uiRequest) && context.uiRequest.serverContext &&
					dql::policy::CanSendCompanionPcv(AccessState(), *context.uiRequest.serverContext) &&
					StockDestinationMatches() && ResolveCurrentOperationActor(operation->second, actor) &&
					operation->second.expiresAt > CurrentUnixSeconds();
				if (!current) {
					BeginCompanionCancel(context);
					return;
				}
				dql::companion::DispatchGuard guard{
					.requestCurrent = true, .actorKeyMapped = true,
					.actorAlive = actor && !actor->IsDeleted() && !actor->IsDisabled() && !actor->IsDead(),
					.expectedName = operation->second.target->name,
					.expectedFormId = static_cast<std::uint32_t>(std::stoul(operation->second.target->formId, nullptr, 16)),
					.currentName = operation->second.target->name,
					.currentFormId = static_cast<std::uint32_t>(actor->GetFormID())
				};
				bool papyrusInvoked = false;
				const auto dispatch = dql::companion::DispatchStockPlayerInput(token, guard, actor,
					[&](std::string_view papyrusClass, std::string_view function, std::string_view message,
						std::string_view messageType, RE::Actor* targetActor) {
						auto* vm = RE::SkyrimVM::GetSingleton();
						if (!vm || !vm->impl || !targetActor || !StockDestinationMatches()) return false;
						operation->second.papyrusInvocationStarted = true;
						operation->second.phase = "papyrus_submission_unknown";
						papyrusInvoked = true;
						RE::BSTSmartPointer<RE::BSScript::IStackCallbackFunctor> noCallback{};
						const std::string className(papyrusClass);
						const std::string functionName(function);
						return vm->impl->DispatchStaticCall(RE::BSFixedString(className), RE::BSFixedString(functionName),
							RE::MakeFunctionArguments(RE::BSFixedString(message), RE::BSFixedString(messageType),
								static_cast<RE::Actor*>(targetActor)), noCallback);
					});
				if (!papyrusInvoked || dispatch == dql::companion::DispatchResult::notAttempted) {
					BeginCompanionCancel(context);
					return;
				}
				operation->second.phase = dispatch == dql::companion::DispatchResult::queueAccepted ?
					"papyrus_scheduled" : "papyrus_submission_unknown";
				RespondCompanionState(context.uiRequest, context.operationId, "prepared",
					operation->second.expected, operation->second.phase);
				return;
			}

			if (!data) {
				if (auto operation = companionOperations_.find(context.operationId); operation != companionOperations_.end()) {
					operation->second.state = "unknown";
					operation->second.phase = context.kind == CompanionHttpKind::cancel ? "cancel_uncertain" : "status_uncertain";
				}
				RespondCompanionState(context.uiRequest, context.operationId, "unknown", std::nullopt,
					context.kind == CompanionHttpKind::cancel ? "cancel_uncertain" : "status_uncertain");
				return;
			}
			const auto operation = companionOperations_.find(context.operationId);
			const auto originalExpected = operation == companionOperations_.end() ?
				std::optional<dql::companion::ExpectedConfigIds>{} : std::optional(operation->second.expected);
			const auto originalTarget = operation == companionOperations_.end() ?
				std::optional<dql::companion::TargetIdentity>{} : operation->second.target;
			const auto projection = dql::companion::ProjectStatus(*data, context.operationId, originalExpected, originalTarget);
			if (!projection) {
				RespondCompanionState(context.uiRequest, context.operationId, "unknown", std::nullopt, "invalid_status");
				return;
			}
			if (operation == companionOperations_.end() && projection->state != "unknown") {
				CompanionOperation recovered{
					.operationId = context.operationId, .state = projection->state, .phase = "native_history_missing",
					.target = projection->target, .expected = projection->expected,
					.viewToken = context.uiRequest.viewToken, .nativeGeneration = context.uiRequest.nativeGeneration,
					.serverContext = context.uiRequest.serverContext.value_or(std::string{}),
					.expiresAt = projection->expiresAt.value_or(0), .papyrusInvocationStarted = true,
					.sequence = ++companionSequenceCounter_
				};
				companionOperations_.emplace(context.operationId, std::move(recovered));
			}
			auto tracked = companionOperations_.find(context.operationId);
			if (projection->state == "prepared" && tracked != companionOperations_.end() &&
				!tracked->second.papyrusInvocationStarted) {
				tracked->second.state = "prepared";
				tracked->second.expiresAt = projection->expiresAt.value_or(0);
				BeginCompanionCancel(context);
				return;
			}
			if (tracked != companionOperations_.end()) {
				tracked->second.state = projection->state;
				tracked->second.phase = projection->state == "consumed" ? "server_consumed" :
					projection->state == "prepared" ? tracked->second.phase : projection->state;
			}
			RespondCompanionState(context.uiRequest, context.operationId, projection->state,
				projection->state == "unknown" ? std::nullopt : std::optional(projection->expected),
				projection->state == "unknown" ? "status_uncertain" :
					tracked == companionOperations_.end() ? "server_status" : tracked->second.phase);
		}

		void HandleRequest(std::string raw)
		{
			StringWiper wipeRaw(raw);
			auto parsed = dql::contract::ParseRequest(raw);
			if (!parsed.envelope) {
				return;
			}
			auto request = std::move(*parsed.envelope);
			parsed.envelope.reset();
			if (!IsOwnViewValid() || request.viewToken != viewToken_ || request.nativeGeneration != std::to_string(generation_)) {
				return;
			}
			if (request.operation == "view.close") {
				Respond(request, dql::contract::Json{ { "closed", true } });
				CloseView(false);
				return;
			}
			if (request.operation == "bootstrap") {
				ClearServerAccess();
				SubmitServerRequest(request, request.payload);
				return;
			}
			if (!request.serverContext || request.serverContext->empty() || *request.serverContext != serverContext_) {
				RespondPreDispatchError(request, serverAuthenticated_ ? "stale_context" : "unauthorized",
					"The Department authorization context is no longer current.");
				return;
			}
			if (!serverAuthenticated_) {
				RespondPreDispatchError(request, "unauthorized", "An authenticated Department pairing is required.");
				return;
			}
			if (request.operation == "correspondence.inventory.read") {
				if (!dql::policy::CanUseCorrespondence(AccessState(), *request.serverContext)) {
					RespondError(request, serverAccessCompose_ ? "unavailable" : "forbidden",
						"The current pairing cannot access correspondence inventory.");
					return;
				}
				try {
					Respond(request, BuildInventorySnapshot());
				} catch (const std::exception&) {
					RespondError(request, "unavailable", "The player inventory is unavailable.");
				}
				return;
			}
			if (request.operation == "correspondence.compose") {
				if (!dql::policy::CanUseCorrespondence(AccessState(), *request.serverContext)) {
					RespondPreDispatchError(request, serverAccessCompose_ ? "unavailable" : "forbidden",
						"The current pairing cannot compose correspondence.");
					return;
				}
				auto prepared = PrepareComposePayload(request);
				if (!prepared.payload) {
					RespondPreDispatchError(request, prepared.errorCode, prepared.message);
				} else {
					SubmitServerRequest(request, std::move(*prepared.payload));
				}
				return;
			}
			const bool isPcvOperation = request.operation == "pcv.roster.read" || request.operation == "pcv.status.read" ||
				request.operation == "pcv.arm" || request.operation == "pcv.end" || request.operation == "pcv.send" ||
				request.operation == "pcv.send.status";
			if (isPcvOperation && (!serverAccessPcv_ || !serverCapabilityPcv_)) {
				RespondPreDispatchError(request, serverAccessPcv_ ? "unavailable" : "forbidden",
					"The current pairing or CHIM installation does not allow this PCV operation.");
				return;
			}
			if (request.operation == "pcv.send") {
				BeginPcvSend(std::move(request));
				return;
			}
			if (request.operation == "pcv.send.status") {
				BeginPcvSendStatus(request);
				return;
			}
			if (request.operation == "pcv.roster.read") {
				BeginManagedRoster(request);
				return;
			}
			SubmitServerRequest(request, request.payload);
		}

		void Respond(const dql::contract::Envelope& request, const dql::contract::Json& data)
		{
			if (!IsOwnViewValid() || request.viewToken != viewToken_) {
				return;
			}
			const auto raw = dql::contract::MakeResponse(request, data).dump();
			prisma_->InteropCall(view_, "departmentReceive", raw.c_str());
		}

		void RespondError(const dql::contract::Envelope& request, std::string_view code, std::string_view message,
			std::optional<bool> dispatched = std::nullopt)
		{
			if (!IsOwnViewValid() || request.viewToken != viewToken_) {
				return;
			}
			const auto raw = dql::contract::MakeError(request, code, message, dispatched).dump();
			prisma_->InteropCall(view_, "departmentReceive", raw.c_str());
		}

		void RespondPreDispatchError(const dql::contract::Envelope& request, std::string_view code, std::string_view message)
		{
			if (!IsOwnViewValid() || request.viewToken != viewToken_) {
				return;
			}
			const auto raw = dql::contract::MakePreDispatchError(request, code, message).dump();
			prisma_->InteropCall(view_, "departmentReceive", raw.c_str());
		}

		static bool SetOpenKeyCode(RE::StaticFunctionTag*, std::int32_t keyCode)
		{
			return Instance().SetOpenKey(keyCode);
		}

		static std::int32_t GetOpenKeyCode(RE::StaticFunctionTag*)
		{
			return Instance().openKeyCode_.load(std::memory_order_relaxed);
		}

		static void ToggleFromPapyrus(RE::StaticFunctionTag*)
		{
			EnqueueMain([] { Instance().ToggleView(); });
		}

		static bool RegisterPapyrus(RE::BSScript::IVirtualMachine* vm)
		{
			if (!vm) {
				return false;
			}
			vm->RegisterFunction("SetOpenKeyCode", kPapyrusClass, SetOpenKeyCode);
			vm->RegisterFunction("GetOpenKeyCode", kPapyrusClass, GetOpenKeyCode);
			vm->RegisterFunction("ToggleView", kPapyrusClass, ToggleFromPapyrus);
			return true;
		}

		PRISMA_UI_API::IVPrismaUI1* prisma_{ nullptr };
		dql::http::HttpTransport transport_;
		PrismaView view_{ 0 };
		std::uint64_t generation_{ 1 };
		std::uint64_t pairIdentity_{ 1 };
		std::atomic<std::int32_t> openKeyCode_{ -1 };
		std::string viewToken_;
		std::string serverContext_;
		std::string scopeToken_;
		std::wstring serverBase_;
		std::wstring pairToken_;
		std::wstring stockIniCwd_;
		std::wstring stockIniPath_;
		std::string stockIniSnapshot_;
		std::unordered_map<std::string, CompanionOperation> companionOperations_;
		std::uint64_t companionSequenceCounter_{ 0 };
		bool stockIniCaptured_{ false };
		bool serverAuthenticated_{ false };
		bool serverAccessPcv_{ false };
		bool serverAccessInspector_{ false };
		bool serverAccessCompose_{ false };
		bool serverAccessAuthorDetail_{ false };
		bool serverCapabilityPcv_{ false };
		bool serverCapabilityMp_{ false };
		bool serverCapabilitySas_{ false };
		bool serverCapabilityCorrespondence_{ false };
		bool pluginsLoaded_{ false };
		bool inputRegistered_{ false };
		bool creatingView_{ false };
		bool viewReadyToInitialize_{ false };
		dql::policy::ViewInitializeState<PrismaView> initializeState_;
		std::uint64_t inventoryRevisionCounter_{ 0 };
		std::string inventoryRevision_;
		std::unordered_map<std::string, InventorySelection> inventorySelections_;
		std::optional<dql::contract::Envelope> pendingRosterRequest_;
		dql::contract::Json pendingRosterPayload_{ dql::contract::Json::object() };
		std::unordered_map<std::string, RE::ActorHandle> managedRoster_;
		std::unordered_map<std::string, RE::ActorHandle> managedActorKeys_;
		std::unordered_map<std::string, std::string> managedActorLabels_;
		std::vector<std::string> managedRosterNames_;
		std::string nativeRosterRevision_;
		std::uint64_t nativeRosterRevisionCounter_{ 0 };
		bool managedRosterLimited_{ false };
		bool managedRosterReady_{ false };

		friend bool RegisterPapyrusFunctions(RE::BSScript::IVirtualMachine*);
		friend NativeShell& GetShell();
		friend void OnMessage(SKSE::MessagingInterface::Message*);
		friend void OnRevert(SKSE::SerializationInterface*);
		friend void RegisterForRevert();
	};

	NativeShell& GetShell()
	{
		return NativeShell::Instance();
	}

	bool RegisterPapyrusFunctions(RE::BSScript::IVirtualMachine* vm)
	{
		return NativeShell::RegisterPapyrus(vm);
	}

	void OnRevert(SKSE::SerializationInterface*)
	{
		NativeShell::EnqueueMain([] { GetShell().OnRevert(); });
	}

	void RegisterForRevert()
	{
		if (const auto* serialization = SKSE::GetSerializationInterface()) {
			serialization->SetRevertCallback(&OnRevert);
		}
	}

	void OnMessage(SKSE::MessagingInterface::Message* message)
	{
		if (!message) {
			return;
		}
		switch (message->type) {
		case SKSE::MessagingInterface::kPostLoad:
			NativeShell::EnqueueMain([] { GetShell().OnPostLoad(); });
			break;
		case SKSE::MessagingInterface::kPreLoadGame:
			NativeShell::EnqueueMain([] { GetShell().OnPreLoadGame(); });
			break;
		case SKSE::MessagingInterface::kNewGame:
			NativeShell::EnqueueMain([] { GetShell().OnNewGame(); });
			break;
		default:
			break;
		}
	}
}

SKSEPluginInfo(.Version = "0.1.0.0"_v,
	.Name = "WorldOfDramaLlama")

SKSEPluginLoad(const SKSE::LoadInterface* skse)
{
	if (!skse) {
		return false;
	}
	SKSE::Init(skse);
	const auto* messaging = SKSE::GetMessagingInterface();
	const auto* papyrus = SKSE::GetPapyrusInterface();
	if (!messaging || !papyrus || !messaging->RegisterListener(&OnMessage) || !papyrus->Register(&RegisterPapyrusFunctions)) {
		return false;
	}
	RegisterForRevert();
	return true;
}
