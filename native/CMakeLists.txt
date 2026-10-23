cmake_minimum_required(VERSION 3.26)
project(DepartmentOfQuestionableDecisionsNative LANGUAGES CXX)

option(DQL_BUILD_SKSE_PLUGIN "Build the Skyrim SKSE plugin" OFF)
set(DQL_COMMONLIB_SOURCE "" CACHE PATH "Pinned CommonLibSSE-NG source directory")
set(DQL_RAPIDCSV_INCLUDE "" CACHE PATH "Pinned rapidcsv include directory")
set(DQL_NLOHMANN_INCLUDE_ROOT "" CACHE PATH "Pinned nlohmann/json.hpp include root")
set(DQL_PLUGIN_OUTPUT_DIRECTORY "" CACHE PATH "Fresh output directory for the Skyrim plugin DLL")

if(NOT EXISTS "${DQL_NLOHMANN_INCLUDE_ROOT}/nlohmann/json.hpp")
	message(FATAL_ERROR "Set DQL_NLOHMANN_INCLUDE_ROOT to the pinned nlohmann include root for host tests and the plugin.")
endif()

if(MSVC)
	# Remap workspace source paths before building the plugin and its static dependencies.
	add_compile_options("/experimental:deterministic" "/pathmap:${CMAKE_CURRENT_SOURCE_DIR}=." "/pathmap:${DQL_NLOHMANN_INCLUDE_ROOT}=deps/nlohmann")
endif()

add_library(dql_contract STATIC src/Contract.cpp src/NativePolicy.cpp src/PcvCompanionPolicy.cpp src/ConnectionProtocol.cpp)
target_compile_features(dql_contract PUBLIC cxx_std_20)
target_include_directories(dql_contract PUBLIC src "${DQL_NLOHMANN_INCLUDE_ROOT}")

include(CTest)
if(BUILD_TESTING)
	add_executable(dql_native_boundary_tests tests/NativeBoundaryTests.cpp)
	target_link_libraries(dql_native_boundary_tests PRIVATE dql_contract)
	target_include_directories(dql_native_boundary_tests PRIVATE tests/fixtures)
	add_test(NAME dql_native_boundary_tests COMMAND dql_native_boundary_tests)
	set_tests_properties(dql_native_boundary_tests PROPERTIES
		ENVIRONMENT "DQL_PLUGIN_SOURCE_PATH=${CMAKE_CURRENT_SOURCE_DIR}/src/Plugin.cpp;DQL_REFUSAL_FIXTURE_PATH=${CMAKE_CURRENT_BINARY_DIR}/../c2-02-refusal-fixture.json;DQL_QUEUE_REFUSAL_FIXTURE_PATH=${CMAKE_CURRENT_BINARY_DIR}/../c2-02-queue-refusal-fixture.json")

	add_executable(dql_pcv_companion_policy_tests tests/PcvCompanionPolicyTests.cpp)
	target_link_libraries(dql_pcv_companion_policy_tests PRIVATE dql_contract)
	add_test(NAME dql_pcv_companion_policy_tests COMMAND dql_pcv_companion_policy_tests)
	set_tests_properties(dql_pcv_companion_policy_tests PROPERTIES
		ENVIRONMENT "DQL_PCV_COMPANION_FIXTURE_PATH=${CMAKE_CURRENT_SOURCE_DIR}/tests/fixtures/php-companion-json-fixtures.json")

	add_executable(dql_contract_tests tests/ContractTests.cpp)
	target_link_libraries(dql_contract_tests PRIVATE dql_contract)
	add_test(NAME dql_contract_tests COMMAND dql_contract_tests)

	add_executable(dql_connection_protocol_tests tests/ConnectionProtocolTests.cpp)
	target_link_libraries(dql_connection_protocol_tests PRIVATE dql_contract)
	add_test(NAME dql_connection_protocol_tests COMMAND dql_connection_protocol_tests)

	add_executable(dql_http_transport_tests tests/http-transport-tests.cpp src/HttpTransport.cpp)
	target_compile_features(dql_http_transport_tests PRIVATE cxx_std_20)
	target_compile_definitions(dql_http_transport_tests PRIVATE UNICODE _UNICODE NOMINMAX)
	target_include_directories(dql_http_transport_tests PRIVATE src)
	target_link_libraries(dql_http_transport_tests PRIVATE dql_contract Winhttp Ws2_32)
	add_test(NAME dql_http_transport_tests COMMAND dql_http_transport_tests)
endif()

if(DQL_BUILD_SKSE_PLUGIN)
	if(NOT EXISTS "${DQL_COMMONLIB_SOURCE}/CMakeLists.txt")
		message(FATAL_ERROR "Set DQL_COMMONLIB_SOURCE to the pinned CommonLib source directory.")
	endif()
	if(NOT EXISTS "${DQL_RAPIDCSV_INCLUDE}/rapidcsv.h")
		message(FATAL_ERROR "Set DQL_RAPIDCSV_INCLUDE to the pinned rapidcsv include directory.")
	endif()
	if(NOT EXISTS "${CMAKE_CURRENT_SOURCE_DIR}/deps/PrismaUI_API.h")
		message(FATAL_ERROR "Pinned PrismaUI API header is missing from native/deps.")
	endif()
	if(DQL_PLUGIN_OUTPUT_DIRECTORY STREQUAL "")
		message(FATAL_ERROR "Set DQL_PLUGIN_OUTPUT_DIRECTORY to a fresh output directory.")
	endif()
	if(MSVC)
		add_compile_options(
			"/pathmap:${DQL_COMMONLIB_SOURCE}=deps/commonlib"
			"/pathmap:${DQL_RAPIDCSV_INCLUDE}=deps/rapidcsv"
			"/pathmap:${CMAKE_PREFIX_PATH}=deps/install")
	endif()

	set(BUILD_TESTS OFF CACHE BOOL "" FORCE)
	set(ENABLE_SKYRIM_SE ON CACHE BOOL "" FORCE)
	set(ENABLE_SKYRIM_AE ON CACHE BOOL "" FORCE)
	set(ENABLE_SKYRIM_VR OFF CACHE BOOL "" FORCE)
	set(SKSE_SUPPORT_XBYAK OFF CACHE BOOL "" FORCE)
	set(RAPIDCSV_INCLUDE_DIRS "${DQL_RAPIDCSV_INCLUDE}" CACHE PATH "" FORCE)
	add_subdirectory("${DQL_COMMONLIB_SOURCE}" "${CMAKE_CURRENT_BINARY_DIR}/commonlib")

	add_library(dql_skse SHARED src/Plugin.cpp src/HttpTransport.cpp)
	target_compile_features(dql_skse PRIVATE cxx_std_20)
	target_compile_definitions(dql_skse PRIVATE UNICODE _UNICODE NOMINMAX)
	target_include_directories(dql_skse PRIVATE src deps "${DQL_NLOHMANN_INCLUDE_ROOT}" "${DQL_RAPIDCSV_INCLUDE}")
	target_link_libraries(dql_skse PRIVATE dql_contract CommonLibSSE::CommonLibSSE Winhttp Ole32 Advapi32 Bcrypt Shell32)
	if(MSVC)
		target_link_options(dql_skse PRIVATE "/Brepro" "/PDBALTPATH:WorldOfDramaLlama.pdb")
	endif()
	set_target_properties(dql_skse PROPERTIES
		OUTPUT_NAME "WorldOfDramaLlama"
		PREFIX ""
		RUNTIME_OUTPUT_DIRECTORY "${DQL_PLUGIN_OUTPUT_DIRECTORY}"
		LIBRARY_OUTPUT_DIRECTORY "${DQL_PLUGIN_OUTPUT_DIRECTORY}"
		RUNTIME_OUTPUT_DIRECTORY_RELEASE "${DQL_PLUGIN_OUTPUT_DIRECTORY}"
		LIBRARY_OUTPUT_DIRECTORY_RELEASE "${DQL_PLUGIN_OUTPUT_DIRECTORY}")
endif()


