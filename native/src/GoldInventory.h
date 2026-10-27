#pragma once

#include <cstdint>

namespace dql::inventory
{
	template <class Inventory>
	[[nodiscard]] std::int32_t GoldAmountFromInventory(const Inventory& inventory) noexcept
	{
		for (const auto& [form, row] : inventory) {
			if (form && form->IsGold()) {
				return row.first > 0 ? row.first : 0;
			}
		}
		return 0;
	}
}
