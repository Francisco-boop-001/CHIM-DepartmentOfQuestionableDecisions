#include "Contract.h"

#include <cstdlib>
#include <fstream>
#include <iostream>
#include <set>
#include <stdexcept>
#include <string>
#include <string_view>

using dql::contract::Json;

#define CHECK(condition) do { if (!(condition)) throw std::runtime_error("check failed: " #condition); } while (false)

namespace
{
	void Reject(const Json& data, std::string_view mutation)
	{
		if (dql::contract::ValidateBootstrapData(data)) {
			throw std::runtime_error("bootstrap validator accepted mutation: " + std::string(mutation));
		}
	}

	void CheckBooleanValues(const Json& values, bool expected)
	{
		for (const auto& [_, value] : values.items()) {
			CHECK(value.is_boolean());
			CHECK(value.get<bool>() == expected);
		}
	}
}

int main(int argc, char** argv)
{
	try {
		const char* fixturePath = argc == 2 ? argv[1] : std::getenv("DQL_SERVER_BOOTSTRAP_FIXTURE_PATH");
		if (argc > 2 || !fixturePath || *fixturePath == '\0') {
			throw std::runtime_error("set DQL_SERVER_BOOTSTRAP_FIXTURE_PATH or pass one fixture path");
		}
		std::ifstream input(fixturePath, std::ios::binary);
		if (!input) throw std::runtime_error("could not open PHP bootstrap fixture file");
		const Json fixture = Json::parse(input);
		CHECK(fixture.is_object() && fixture.size() == 4);
		CHECK(fixture.contains("schemaVersion") && fixture["schemaVersion"] == 1);
		CHECK(fixture.contains("cases") && fixture["cases"].is_array() && fixture["cases"].size() == 4);
		CHECK(fixture.contains("sourceFiles") && fixture["sourceFiles"].is_array() && !fixture["sourceFiles"].empty());
		CHECK(fixture.contains("stateCleanup") && fixture["stateCleanup"] == "removed");
		for (const auto& source : fixture["sourceFiles"]) {
			CHECK(source.is_string());
			const auto& path = source.get_ref<const std::string&>();
			CHECK(!path.empty() && path.find(':') == std::string::npos && path.front() != '/' && path.front() != '\\');
			CHECK(path.find("..") == std::string::npos);
		}

		std::set<std::string> seen;
		Json authenticatedData;
		Json unauthenticatedData;
		Json brainwashOnlyData;
		Json authenticatedResponse;
		dql::contract::Envelope authenticatedRequest;
		for (const auto& testCase : fixture["cases"]) {
			CHECK(testCase.is_object() && testCase.size() == 6);
			CHECK(testCase.contains("name") && testCase["name"].is_string());
			CHECK(testCase.contains("requestBody") && testCase["requestBody"].is_string());
			CHECK(testCase.contains("responseStatus") && testCase["responseStatus"].is_number_integer());
			CHECK(testCase.contains("responseBody") && testCase["responseBody"].is_string());
			CHECK(testCase.contains("authenticated") && testCase["authenticated"].is_boolean());
			CHECK(testCase.contains("brainwash") && testCase["brainwash"].is_boolean());

			const auto& name = testCase["name"].get_ref<const std::string&>();
			CHECK(seen.insert(name).second);
			const auto status = testCase["responseStatus"].get<int>();
			CHECK(status >= 200 && status < 300);
			const auto request = dql::contract::ParseRequest(testCase["requestBody"].get_ref<const std::string&>());
			if (!request.envelope || request.envelope->operation != "bootstrap") {
				throw std::runtime_error("PHP fixture contains an invalid bootstrap request: " + name);
			}
			const auto response = dql::contract::ParseServerResponse(
				testCase["responseBody"].get_ref<const std::string&>(), *request.envelope);
			if (!response.value || !(*response.value)["ok"].get<bool>()) {
				throw std::runtime_error("PHP fixture contains an invalid bootstrap response: " + name);
			}
			const auto& data = (*response.value)["data"];
			if (!dql::contract::ValidateBootstrapData(data)) {
				throw std::runtime_error("native validator rejected actual PHP bootstrap fixture: " + name);
			}
			CHECK(data["authenticated"] == testCase["authenticated"]);
			CHECK(data["access"]["brainwash"] == testCase["brainwash"]);

			if (name == "unauthenticated_bootstrap") {
				CHECK(!testCase["authenticated"].get<bool>() && !testCase["brainwash"].get<bool>());
				unauthenticatedData = data;
			} else if (name == "authenticated_all_readiness_disabled") {
				CHECK(testCase["authenticated"].get<bool>() && !testCase["brainwash"].get<bool>());
				CheckBooleanValues(data["capabilities"], false);
			} else if (name == "authenticated_full_access") {
				CHECK(testCase["authenticated"].get<bool>() && testCase["brainwash"].get<bool>());
				CheckBooleanValues(data["access"], true);
				CHECK(data.contains("perspectives") && data["perspectives"].is_object());
				authenticatedData = data;
				authenticatedResponse = Json::parse(testCase["responseBody"].get_ref<const std::string&>());
				authenticatedRequest = *request.envelope;
			} else if (name == "authenticated_brainwash_only") {
				CHECK(testCase["authenticated"].get<bool>() && testCase["brainwash"].get<bool>());
				CHECK(data["access"]["brainwash"].get<bool>() && !data["access"]["inspector"].get<bool>());
				CHECK(!data.contains("perspectives"));
				for (const auto key : { "pcv", "compose", "authorDetail" }) CHECK(!data["access"][key].get<bool>());
				brainwashOnlyData = data;
			}
		}

		for (const auto name : { "unauthenticated_bootstrap", "authenticated_all_readiness_disabled",
			"authenticated_full_access", "authenticated_brainwash_only" }) {
			CHECK(seen.contains(name));
		}
		CHECK(seen.size() == 4);
		CHECK(!unauthenticatedData.is_null() && !authenticatedData.is_null() && !brainwashOnlyData.is_null());

		for (const auto name : { "capabilities", "access" }) {
			auto missingBrainwash = authenticatedData;
			missingBrainwash[name].erase("brainwash");
			Reject(missingBrainwash, std::string("missing ") + name + ".brainwash");
		}
		auto extraTopLevel = authenticatedData;
		extraTopLevel["unexpected"] = true;
		Reject(extraTopLevel, "unknown top-level field");
		auto extraCapability = authenticatedData;
		extraCapability["capabilities"]["unexpected"] = true;
		Reject(extraCapability, "unknown capability field");
		auto extraAccess = authenticatedData;
		extraAccess["access"]["unexpected"] = true;
		Reject(extraAccess, "unknown access field");
		auto badCapabilityType = authenticatedData;
		badCapabilityType["capabilities"]["brainwash"] = "true";
		Reject(badCapabilityType, "non-boolean capability");
		auto badAccessType = authenticatedData;
		badAccessType["access"]["brainwash"] = 1;
		Reject(badAccessType, "non-boolean permission");
		auto badAuthenticatedType = authenticatedData;
		badAuthenticatedType["authenticated"] = 1;
		Reject(badAuthenticatedType, "non-boolean authenticated flag");
		auto badContext = authenticatedData;
		badContext["serverContext"] = "bad\ncontext";
		Reject(badContext, "unsafe server context");
		auto badScope = authenticatedData;
		badScope["scopeToken"] = std::string(42, 'a') + "!";
		Reject(badScope, "invalid scope token");
		auto badPerspective = brainwashOnlyData;
		badPerspective["perspectives"] = Json::object();
		Reject(badPerspective, "perspectives without inspector permission");
		auto badAuthorDetail = brainwashOnlyData;
		badAuthorDetail["access"]["authorDetail"] = true;
		Reject(badAuthorDetail, "author detail without inspector permission");
		auto unauthenticatedPermission = unauthenticatedData;
		unauthenticatedPermission["access"]["brainwash"] = true;
		Reject(unauthenticatedPermission, "unauthenticated Brainwash access");
		auto unauthenticatedCapability = unauthenticatedData;
		unauthenticatedCapability["capabilities"]["brainwash"] = true;
		Reject(unauthenticatedCapability, "unauthenticated Brainwash capability");
		auto mismatchedAuthRequired = authenticatedData;
		mismatchedAuthRequired["authRequired"] = true;
		Reject(mismatchedAuthRequired, "authRequired mismatch");

		auto badEcho = authenticatedResponse;
		badEcho["requestId"] = "123e4567-e89b-12d3-a456-426614174099";
		CHECK(!dql::contract::ParseServerResponse(badEcho.dump(), authenticatedRequest).value.has_value());
		badEcho = authenticatedResponse;
		badEcho["context"]["nativeGeneration"] = "43";
		CHECK(!dql::contract::ParseServerResponse(badEcho.dump(), authenticatedRequest).value.has_value());
		std::cout << "server bootstrap fixtures passed: " << seen.size() << " actual PHP cases; malformed mutations refused\n";
		return 0;
	} catch (const std::exception& error) {
		std::cerr << error.what() << '\n';
		return 1;
	}
}
