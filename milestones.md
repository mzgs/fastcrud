# FastCRUD Enhancement Milestones

## 1. Core Data Layer
- Implement query configuration: `where`, `or_where`, `no_quotes`, `order_by`, `limit`, `limit_list`, `search_columns`
- Support custom select sources: `joins`, `relations`, `query`, subselects, pagination aware counts
- Return enriched metadata via AJAX for search/filter UI scaffolding

## 2. Column & Table Presentation
- Add column configuration APIs: `columns`, `label`/`column_name`, `column_pattern`, `column_callback`
- Enable visual enhancements: `highlight`, `highlight_row`, `column_class`, `column_width`, `column_cut`, custom buttons, duplicate toggle
- Surface table-level info (`table_name`, tooltips, icons) and summary `sum` rows

## 3. Form Engine
- Provide field layout controls: `fields`, tab support, default tab, reverse modes per operation
- Implement field behaviours: `change_type`, `pass_var`, `pass_default`, `readonly`, `disabled`, `no_editor`
- Integrate validation helpers: `validation_required`, `validation_pattern`, `unique`

## 4. Lifecycle & Hooks
- Add CRUD lifecycle callbacks: before/after insert, update, delete, replace/delete actions
- Implement creation/duplication flows, custom actions, nested tables, FK relations
- Wire advanced features: alerts, mass alerts, interactive callbacks, file uploads, custom actions

