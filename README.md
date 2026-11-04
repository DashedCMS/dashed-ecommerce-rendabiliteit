# Dashed Ecommerce Rendabiliteit

Profitability reporting (revenue, costs, margin, advertising) for Dashed e-commerce shops.

Shops extend the package through the `rendabiliteit()` registry: a cost source (`kostprijsBron`),
excluded product groups, cost links, size keys and extra signals.

## Registry

- `kostprijsBron(KostprijsBron)`: where the cost price of a product comes from (default `InkoopprijsBron`, `purchase_price`).
- `sluitGroepenUit(Closure(): array<int>)`: groups that do not count in cost price and margin; in "Aandachtspunten" their sold lines without cost form one info row.
- `kostprijsLink(Closure(int $groepId, string $sleutel): ?array)`: `['url', 'label']`, optionally `reden` and `maat`, for "mist kostprijs".
- `maatsleutel(Closure(Product): string)` or the faster `maatsleutels(Closure(iterable): array<int, string>)`: size key per product.
- `extraSignalen(Closure(Collection): list<array>)`: e.g. `['soort' => 'verwacht_duurder', 'product_id' => 12]` for a variant that may be more expensive.

Carriers come from `config('dashed-ecommerce-rendabiliteit.vervoerders')`; only those whose label table exists take part.
