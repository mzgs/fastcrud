# FastCRUD Enhancement Milestones

## 1. Core Data Layer
- Implement query configuration: `where`, `or_where`, `no_quotes`, `order_by`, `limit`, `limit_list`, `search_columns`
- Support custom select sources: `joins`, `relations`, `query`, subselects, pagination aware counts
- Return enriched metadata via AJAX for search/filter UI scaffolding

## 2. Column & Table Presentation
- **Column configuration APIs**: Extend `FastCrud\Crud` with fluent methods (`set_column_labels`, `column_pattern`, `column_callback`, `column_class`, `column_width`, `column_cut`, `column_buttons`) that normalize column identifiers, persist metadata in the existing config array, and serialize values through `buildClientConfigPayload()`/`buildMeta()` for AJAX consumers. Column callbacks now return raw HTML that FastCRUD injects directly into the table.
- **Rendering pipeline updates**: Teach `buildHeader()` and `buildBody()` to honour the new metadata—apply custom labels, inject Bootstrap utility classes for width/highlights, truncate values when `column_cut` is set, and render optional per-column button groups or duplicate toggles. Ensure every rendered fragment routes through `escapeHtml()` unless an explicit `no_escape` flag is supplied.
- **Table metadata & summaries**: Allow table-level descriptors (`table_name`, tooltip text, icon class) plus per-column summary aggregations (`sum`, `avg`, etc.). Surface these via `buildMeta()` and append summary rows after the body using Bootstrap table helpers. Aggregate calculations must use prepared statements and reuse the existing query builder to respect filters.
- **jQuery integration**: Update `generateAjaxScript()` to hydrate column metadata (classes, widths, tooltips, highlight rules) on the client, render summary rows, and wire delegated handlers for custom column buttons plus the duplicate toggle. Follow the documented AJAX pattern, use Bootstrap 5 components (tooltips, buttons), and keep all DOM work inside the jQuery wrapper.

## 3. Form Engine
- Provide field layout controls: `fields`, tab support, default tab, reverse modes per operation
- Implement field behaviours: `change_type`, `pass_var`, `pass_default`, `readonly`, `disabled`
- Integrate validation helpers: `validation_required`, `validation_pattern`, `unique`

## 4. Lifecycle & Hooks
- Add CRUD lifecycle callbacks: before/after insert, update, delete, replace/delete actions
- Implement creation/duplication flows, custom actions, nested tables, FK relations
- Wire advanced features: alerts, mass alerts, interactive callbacks, file uploads, custom actions
