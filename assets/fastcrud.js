(function(window) {
    if (window.FastCrudRuntime) { return; }
    var initialized = new WeakSet();
    function initialize(options) {
    function FastCrudInit($) {
        $(document).ready(function() {
        var root = document.getElementById(options.id);
        if (!root || initialized.has(root)) { return; }
        initialized.add(root);
        function deepClone(value) {
            if (value === null || typeof value === 'undefined') {
                return value;
            }

            try {
                return JSON.parse(JSON.stringify(value));
            } catch (error) {
                if (Array.isArray(value)) {
                    return $.extend(true, [], value);
                }
                if (value && typeof value === 'object') {
                    return $.extend(true, {}, value);
                }
            }

            return value;
        }

        function extractAjaxErrorMessage(jqXHR, fallback) {
            if (jqXHR && jqXHR.responseJSON && typeof jqXHR.responseJSON.error === 'string') {
                var jsonError = jqXHR.responseJSON.error.trim();
                if (jsonError) {
                    return jsonError;
                }
            }
            if (jqXHR && typeof jqXHR.responseText === 'string') {
                var raw = jqXHR.responseText.trim();
                if (raw) {
                    try {
                        var parsed = JSON.parse(raw);
                        if (parsed && typeof parsed.error === 'string') {
                            var parsedError = parsed.error.trim();
                            if (parsedError) {
                                return parsedError;
                            }
                        }
                    } catch (ignored) {
                    }
                    var textContent = $('<div>').html(raw).text().trim();
                    if (textContent) {
                        return textContent;
                    }
                    return raw;
                }
            }
            if (typeof fallback === 'string' && fallback.trim() !== '') {
                return fallback;
            }
            return 'Failed to load data';
        }

        var tableId = options.id;
        var styleDefaults = options.styles;
        var textDefaults = options.texts;
        var editViewHighlightClass = getStyleClass('edit_view_row_highlight_class', 'table-warning');
        var dismissIconClass = getStyleClass('x_icon_class', 'fas fa-xmark');
        var table = $('#' + tableId);
        var tableName = table.data('table');
        var perPage = parseInt(table.data('per-page'), 10);
        if (isNaN(perPage) || perPage < 1) {
            perPage = 5;
        }
        var container = $('#' + tableId + '-container');
        var configKey = String(container.attr('data-fastcrud-config-key') || '');
        var rawConfig = container.attr('data-fastcrud-config');
        var clientConfig = {};
        if (rawConfig) {
            try {
                clientConfig = JSON.parse(rawConfig);
            } catch (error) {
                clientConfig = {};
            }
        }

        function t(key, fallback, replacements) {
            var value = '';
            var found = false;
            if (clientConfig && clientConfig.ui_text && Object.prototype.hasOwnProperty.call(clientConfig.ui_text, key)) {
                value = String(clientConfig.ui_text[key] || '');
                found = true;
            }
            if (!found && textDefaults && Object.prototype.hasOwnProperty.call(textDefaults, key)) {
                value = String(textDefaults[key] || '');
                found = true;
            }
            if (!found) {
                value = typeof fallback === 'string' ? fallback : key;
            }
            if (replacements && typeof replacements === 'object') {
                Object.keys(replacements).forEach(function(token) {
                    value = value.replace(new RegExp('\{' + token + '\}', 'g'), String(replacements[token]));
                });
            }
            return value;
        }

        function hasObjectEntries(value) {
            if (!value || typeof value !== 'object') {
                return false;
            }
            for (var prop in value) {
                if (Object.prototype.hasOwnProperty.call(value, prop)) {
                    return true;
                }
            }
            return false;
        }

        function buildRequestConfigState() {
            if (!configKey || !metaInitialized) {
                return null;
            }

            return {
                order_by: Array.isArray(clientConfig.order_by) ? deepClone(clientConfig.order_by) : [],
                query_builder: clientConfig.query_builder && typeof clientConfig.query_builder === 'object'
                    ? deepClone(clientConfig.query_builder)
                    : {
                        filters: [],
                        logic: 'AND',
                        sorts: [],
                        active_view: null
                    },
                per_page: Object.prototype.hasOwnProperty.call(clientConfig, 'per_page')
                    ? clientConfig.per_page
                    : perPage
            };
        }

        function attachCrudConfig(payload, includeState) {
            if (!payload || typeof payload !== 'object') {
                return payload;
            }

            if (configKey) {
                payload.config_key = configKey;
                if (includeState) {
                    var statePayload = buildRequestConfigState();
                    if (statePayload) {
                        payload.config_state = JSON.stringify(statePayload);
                    }
                }
                return payload;
            }

            if (clientConfig && typeof clientConfig === 'object' && hasObjectEntries(clientConfig)) {
                payload.config = JSON.stringify(clientConfig);
            }

            return payload;
        }

        function appendCrudConfigToFormData(formData, includeState) {
            if (!formData || typeof formData.append !== 'function') {
                return;
            }

            if (configKey) {
                formData.append('config_key', configKey);
                if (includeState) {
                    var statePayload = buildRequestConfigState();
                    if (statePayload) {
                        formData.append('config_state', JSON.stringify(statePayload));
                    }
                }
                return;
            }

            if (clientConfig && typeof clientConfig === 'object' && hasObjectEntries(clientConfig)) {
                formData.append('config', JSON.stringify(clientConfig));
            }
        }

        function appendCrudConfigToParams(params, includeState) {
            if (!params || typeof params.set !== 'function') {
                return;
            }

            if (configKey) {
                params.set('config_key', configKey);
                if (includeState) {
                    var statePayload = buildRequestConfigState();
                    if (statePayload) {
                        params.set('config_state', JSON.stringify(statePayload));
                    }
                }
                return;
            }

            if (clientConfig && typeof clientConfig === 'object' && hasObjectEntries(clientConfig)) {
                params.set('config', JSON.stringify(clientConfig));
            }
        }

        function attachNestedCrudConfig(payload, nestedConfig) {
            if (!payload || typeof payload !== 'object') {
                return payload;
            }

            if (nestedConfig && typeof nestedConfig.config_key === 'string' && nestedConfig.config_key) {
                payload.config_key = nestedConfig.config_key;
                return payload;
            }

            if (nestedConfig && nestedConfig.config && typeof nestedConfig.config === 'object') {
                payload.config = JSON.stringify(nestedConfig.config);
            }

            return payload;
        }

        var requiresCustomFieldHtml = hasObjectEntries(clientConfig.custom_fields)
            || hasObjectEntries(clientConfig.field_callbacks);
        var debugEnabled = !!(clientConfig && clientConfig.debug);

        function toggleActionsCellZIndex(element, isOpen) {
            var cellEl = $(element).closest('td.fastcrud-actions-cell');
            if (!cellEl.length) {
                cellEl = $(element).closest('.fastcrud-actions-cell');
            }
            if (!cellEl.length) {
                return;
            }
            cellEl.toggleClass('fastcrud-actions-open', !!isOpen);
        }

        function measureDropdownMenuHeight(menuEl) {
            if (!menuEl || !menuEl.length) {
                return 0;
            }
            var menuNode = menuEl[0];
            if (menuNode.classList.contains('show')) {
                return menuNode.getBoundingClientRect().height;
            }
            var previousDisplay = menuNode.style.display;
            var previousVisibility = menuNode.style.visibility;
            var previousPosition = menuNode.style.position;
            var previousPointerEvents = menuNode.style.pointerEvents;
            menuNode.style.visibility = 'hidden';
            menuNode.style.display = 'block';
            menuNode.style.position = 'absolute';
            menuNode.style.pointerEvents = 'none';
            var height = menuNode.getBoundingClientRect().height;
            menuNode.style.display = previousDisplay;
            menuNode.style.visibility = previousVisibility;
            menuNode.style.position = previousPosition;
            menuNode.style.pointerEvents = previousPointerEvents;
            return height;
        }

        function updateMultiLinkDropdownPlacement(dropdownEl) {
            var dropdown = dropdownEl && dropdownEl.jquery ? dropdownEl : $(dropdownEl);
            if (!dropdown.length) {
                return;
            }
            dropdown.removeClass('dropup');
            var menu = dropdown.find('.dropdown-menu').first();
            var toggle = dropdown.find('.fastcrud-multi-link-trigger').first();
            if (!menu.length || !toggle.length) {
                return;
            }
            var viewport = getTableViewport();
            if (!viewport || !viewport.length) {
                return;
            }
            menu.css('max-height', '');
            var viewportRect = viewport[0].getBoundingClientRect();
            var toggleRect = toggle[0].getBoundingClientRect();
            var menuHeight = measureDropdownMenuHeight(menu);
            if (!menuHeight) {
                return;
            }
            var viewportTop = Math.max(0, viewportRect.top);
            var viewportBottom = Math.min(window.innerHeight, viewportRect.bottom);
            var spaceBelow = viewportBottom - toggleRect.bottom;
            var spaceAbove = toggleRect.top - viewportTop;
            if (spaceBelow < menuHeight && spaceAbove > spaceBelow) {
                dropdown.addClass('dropup');
            }
            var availableSpace = dropdown.hasClass('dropup') ? spaceAbove : spaceBelow;
            if (availableSpace > 0 && menuHeight > availableSpace) {
                var adjustedHeight = Math.max(0, availableSpace - 8);
                if (adjustedHeight > 0) {
                    menu.css('max-height', adjustedHeight + 'px');
                }
            }
        }

        function updateMultiLinkFilterMenu(menuEl) {
            var menu = menuEl && menuEl.jquery ? menuEl : $(menuEl);
            if (!menu.length) {
                return;
            }
            var input = menu.find('.fastcrud-multi-link-filter-input').first();
            var query = input.length ? String(input.val() || '').trim().toLowerCase() : '';
            var actionWrappers = menu.find('.fastcrud-multi-link-item-wrapper');

            actionWrappers.each(function() {
                var wrapper = $(this);
                var haystack = String(wrapper.attr('data-fastcrud-filter-text') || wrapper.text() || '').toLowerCase();
                wrapper.toggle(!query || haystack.indexOf(query) !== -1);
            });

            menu.find('.fastcrud-multi-link-divider-wrapper').each(function() {
                var divider = $(this);
                if (!query) {
                    divider.show();
                    return;
                }

                var hasVisibleItem = false;
                var cursor = divider.next();
                while (cursor.length) {
                    if (cursor.hasClass('fastcrud-multi-link-divider-wrapper')) {
                        break;
                    }
                    if (cursor.hasClass('fastcrud-multi-link-item-wrapper') && cursor.is(':visible')) {
                        hasVisibleItem = true;
                        break;
                    }
                    cursor = cursor.next();
                }
                divider.toggle(hasVisibleItem);
            });
        }

        function getMultiLinkRowHighlightClasses() {
            return String(editViewHighlightClass || '').split(/\s+/).filter(function(part) {
                return part.length > 0;
            });
        }

        function getMissingRowHighlightClasses(row) {
            var missing = [];
            if (!row || !row.length) {
                return missing;
            }
            getMultiLinkRowHighlightClasses().forEach(function(part) {
                if (!row.hasClass(part)) {
                    missing.push(part);
                }
            });
            return missing;
        }

        function highlightMultiLinkDropdownRow(dropdownEl, highlight) {
            var dropdown = dropdownEl && dropdownEl.jquery ? dropdownEl : $(dropdownEl);
            if (!dropdown.length || String(dropdown.attr('data-fastcrud-highlight-row-on-open') || '') !== '1') {
                return;
            }

            var row = dropdown.closest('tr');
            if (!row.length) {
                return;
            }

            if (highlight) {
                if (dropdown.data('fastcrudMultiLinkHighlighted') === 1 || dropdown.data('fastcrudMultiLinkHighlighted') === '1') {
                    return;
                }
                var missingClasses = getMissingRowHighlightClasses(row);
                dropdown.data('fastcrudMultiLinkAddedClasses', missingClasses.join(' '));
                dropdown.data('fastcrudMultiLinkHighlighted', 1);
                if (missingClasses.length) {
                    row.addClass(missingClasses.join(' '));
                }
                return;
            }

            var addedClasses = String(dropdown.data('fastcrudMultiLinkAddedClasses') || '').trim();
            if (addedClasses && !row.hasClass('fastcrud-editing')) {
                row.removeClass(addedClasses);
            }
            dropdown.removeData('fastcrudMultiLinkAddedClasses').removeData('fastcrudMultiLinkHighlighted');
        }

        table.on('show.bs.dropdown', '.fastcrud-multi-link-trigger, .fastcrud-multi-link-btn', function() {
            var dropdown = $(this).closest('.fastcrud-multi-link-btn');
            highlightMultiLinkDropdownRow(dropdown, true);
            updateMultiLinkFilterMenu(dropdown.find('.fastcrud-multi-link-menu').first());
            updateMultiLinkDropdownPlacement(dropdown);
        });

        table.on('shown.bs.dropdown', '.fastcrud-multi-link-trigger, .fastcrud-multi-link-btn', function() {
            toggleActionsCellZIndex(this, true);
            var dropdown = $(this).closest('.fastcrud-multi-link-btn');
            var filterInput = dropdown.find('.fastcrud-multi-link-filter-input').first();
            if (filterInput.length) {
                filterInput.trigger('focus');
            }
        });

        table.on('hidden.bs.dropdown', '.fastcrud-multi-link-trigger, .fastcrud-multi-link-btn', function() {
            toggleActionsCellZIndex(this, false);
            highlightMultiLinkDropdownRow($(this).closest('.fastcrud-multi-link-btn'), false);
        });

        table.on('click mousedown', '.fastcrud-multi-link-filter-input', function(event) {
            event.stopPropagation();
        });

        table.on('input', '.fastcrud-multi-link-filter-input', function() {
            var input = $(this);
            var menu = input.closest('.fastcrud-multi-link-menu');
            updateMultiLinkFilterMenu(menu);
            updateMultiLinkDropdownPlacement(input.closest('.fastcrud-multi-link-btn'));
        });
        var select2Enabled = !!(clientConfig && clientConfig.select2);
        var filtersEnabled = true;
        if (clientConfig && Object.prototype.hasOwnProperty.call(clientConfig, 'filters_enabled')) {
            filtersEnabled = !!clientConfig.filters_enabled;
        }
        var numbersEnabled = !!(clientConfig && Object.prototype.hasOwnProperty.call(clientConfig, 'numbers_enabled')
            ? clientConfig.numbers_enabled
            : false);
        var compactPagination = !!(clientConfig && Object.prototype.hasOwnProperty.call(clientConfig, 'compact_pagination')
            ? clientConfig.compact_pagination
            : false);
        var searchHidden = !!(clientConfig && Object.prototype.hasOwnProperty.call(clientConfig, 'hide_search')
            ? clientConfig.hide_search
            : false);
        var richEditorConfig = clientConfig.rich_editor || {};
        var paginationContainer = $('#' + tableId + '-pagination');
        var currentPage = 1;
        var columnsCache = [];
        var baseColumns = [];
        var primaryKeyColumn = null;
        var showPrimaryKeyField = !!(clientConfig && clientConfig.form && clientConfig.form.show_primary_key_field);
        var metaConfig = {};
        var metaInitialized = false;
        var perPageOptions = [];
        var searchConfig = { columns: [], default: null };
        var currentSearchTerm = '';
        var currentSearchColumn = null;
        var columnLabels = {};
        var columnClasses = {};
        var columnWidths = {};
        var nestedTablesConfig = Array.isArray(clientConfig.nested_tables) ? clientConfig.nested_tables : [];
        var nestedRowStates = {};
        var rowOrderingConfig = clientConfig.row_ordering && typeof clientConfig.row_ordering === 'object'
            ? deepClone(clientConfig.row_ordering)
            : { enabled: false, column: null };
        var rowOrderingSaving = false;
        var rowOrderingSortable = null;
        var currentPagination = null;
        var sortableState = window.FastCrudSortable || {};
        if (!sortableState.scriptUrl) {
            sortableState.scriptUrl = 'https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js';
        }
        if (!Array.isArray(sortableState.queue)) {
            sortableState.queue = [];
        }
        if (typeof sortableState.loaded !== 'boolean') {
            sortableState.loaded = (typeof window.Sortable !== 'undefined' && typeof window.Sortable.create === 'function');
        } else if (sortableState.loaded && (typeof window.Sortable === 'undefined' || typeof window.Sortable.create !== 'function')) {
            sortableState.loaded = false;
        }
        if (typeof sortableState.loading !== 'boolean') {
            sortableState.loading = false;
        }
        window.FastCrudSortable = sortableState;
        var orderBy = [];
        var queryBuilderConfig = clientConfig.query_builder && typeof clientConfig.query_builder === 'object'
            ? deepClone(clientConfig.query_builder)
            : {};
        var queryBuilderFields = Array.isArray(queryBuilderConfig.fields) ? deepClone(queryBuilderConfig.fields) : [];
        var queryBuilderOperators = Array.isArray(queryBuilderConfig.operators) ? deepClone(queryBuilderConfig.operators) : [];
        var queryBuilderFieldMap = {};
        queryBuilderFields.forEach(function(field) {
            if (!field || typeof field !== 'object') {
                return;
            }
            var id = field.id || field.field;
            if (!id) {
                return;
            }
            var key = String(id);
            var normalizedField = $.extend(true, {}, field);
            normalizedField.id = key;
            normalizedField.field = key;
            if (typeof normalizedField.options === 'object' && !Array.isArray(normalizedField.options)) {
                var optionsArray = [];
                Object.keys(normalizedField.options).forEach(function(optionKey) {
                    optionsArray.push({
                        value: optionKey,
                        label: normalizedField.options[optionKey]
                    });
                });
                normalizedField.options = optionsArray;
            }
            queryBuilderFieldMap[key] = normalizedField;
        });
        var queryBuilderOperatorMap = {};
        queryBuilderOperators.forEach(function(operator) {
            if (!operator || typeof operator !== 'object' || !operator.value) {
                return;
            }
            queryBuilderOperatorMap[String(operator.value)] = operator;
        });
        var queryBuilderState = {
            filters: [],
            logic: queryBuilderConfig.logic === 'OR' ? 'OR' : 'AND',
            sorts: Array.isArray(queryBuilderConfig.sorts) ? deepClone(queryBuilderConfig.sorts) : [],
            activeView: queryBuilderConfig.active_view || null,
            activeViewDirty: false
        };
        if (Array.isArray(queryBuilderConfig.filters)) {
            queryBuilderState.filters = queryBuilderConfig.filters.map(function(filter) {
                var field = filter && typeof filter.field === 'string' ? filter.field : '';
                var operator = filter && typeof filter.operator === 'string' ? filter.operator : 'equals';
                var rawValue = '';
                if (filter && Array.isArray(filter.value)) {
                    rawValue = filter.value.join(', ');
                } else if (filter && filter.value !== null && typeof filter.value !== 'undefined') {
                    rawValue = String(filter.value);
                }
                return {
                    field: field,
                    operator: operator,
                    value: rawValue
                };
            });
        }
        var queryBuilderModal = null;
        var queryBuilderFiltersContainer = null;
        var queryBuilderSortsContainer = null;
        var queryBuilderLogicSelect = null;
        var filtersButton = null;
        var filtersButtonBadge = null;
        var viewSelect = null;
        var deleteViewButton = null;
        var viewStorageNamespace = 'fastcrud:views:';
        var storageKeyAttr = container.attr('data-fastcrud-view-storage-key');
        var viewStorageKey = viewStorageNamespace + (storageKeyAttr && storageKeyAttr.length ? storageKeyAttr : tableId);
        var savedViews = [];

        function getFieldInfo(fieldId) {
            if (!fieldId && fieldId !== 0) {
                return null;
            }
            return queryBuilderFieldMap[String(fieldId)] || null;
        }

        function getOperatorInfo(operator) {
            if (!operator) {
                return null;
            }
            return queryBuilderOperatorMap[String(operator)] || null;
        }

        function getOperatorsForField(fieldId) {
            var info = getFieldInfo(fieldId);
            var type = info && info.type ? String(info.type) : 'string';
            var allowed;
            switch (type) {
                case 'number':
                case 'date':
                case 'datetime':
                case 'time':
                    allowed = ['equals', 'not_equals', 'gt', 'gte', 'lt', 'lte', 'in', 'not_in', 'empty', 'not_empty'];
                    break;
                case 'boolean':
                    allowed = ['equals', 'not_equals', 'in', 'not_in', 'empty', 'not_empty'];
                    break;
                default:
                    allowed = ['contains', 'not_contains', 'equals', 'not_equals', 'in', 'not_in', 'empty', 'not_empty'];
                    break;
            }

            var filtered = [];
            allowed.forEach(function(op) {
                if (getOperatorInfo(op)) {
                    if (filtered.indexOf(op) === -1) {
                        filtered.push(op);
                    }
                }
            });

            if (filtered.length === 0) {
                filtered.push('equals');
            }

            return filtered;
        }

        function convertValueForType(rawValue, type) {
            if (rawValue === null || typeof rawValue === 'undefined') {
                return null;
            }

            if (typeof rawValue !== 'string') {
                rawValue = String(rawValue);
            }

            var trimmed = rawValue.trim();

            if (trimmed === '') {
                return '';
            }

            switch (type) {
                case 'number':
                    if (trimmed === '' || isNaN(Number(trimmed))) {
                        return null;
                    }
                    return Number(trimmed);
                case 'boolean':
                    var lower = trimmed.toLowerCase();
                    if (['1', 'true', 'yes', 'y', 'on'].indexOf(lower) !== -1) {
                        return 1;
                    }
                    if (['0', 'false', 'no', 'n', 'off'].indexOf(lower) !== -1) {
                        return 0;
                    }
                    return null;
                default:
                    return trimmed;
            }
        }

        function convertValuesForType(rawList, type) {
            if (!Array.isArray(rawList)) {
                return [];
            }

            var results = [];
            rawList.forEach(function(item) {
                var converted = convertValueForType(item, type);
                if (converted === null) {
                    return;
                }
                if (typeof converted === 'string' && converted === '') {
                    return;
                }
                results.push(converted);
            });

            return results;
        }

        function buildFilterPayloadForState(filter) {
            if (!filter || typeof filter !== 'object') {
                return null;
            }

            var field = filter.field ? String(filter.field) : '';
            if (!field) {
                return null;
            }

            var operator = filter.operator ? String(filter.operator) : 'equals';
            if (!getOperatorInfo(operator)) {
                operator = 'equals';
            }

            var fieldInfo = getFieldInfo(field) || {};
            var type = fieldInfo.type || 'string';
            var requiresValue = !(operator === 'empty' || operator === 'not_empty');
            var multi = operator === 'in' || operator === 'not_in';

            if (!requiresValue) {
                return {
                    field: field,
                    operator: operator
                };
            }

            var rawValue = filter.value;
            if (typeof rawValue !== 'string') {
                rawValue = rawValue === null || typeof rawValue === 'undefined' ? '' : String(rawValue);
            }

            if (multi) {
                var parts = rawValue.split(',').map(function(piece) {
                    return piece.trim();
                }).filter(function(piece) {
                    return piece.length > 0;
                });

                var convertedList = convertValuesForType(parts, type);
                if (!convertedList.length) {
                    return null;
                }

                return {
                    field: field,
                    operator: operator,
                    value: convertedList
                };
            }

            var converted = convertValueForType(rawValue, type);
            if (converted === null) {
                return null;
            }

            if (typeof converted === 'string' && converted === '') {
                return null;
            }

            return {
                field: field,
                operator: operator,
                value: converted
            };
        }

        function createDefaultFilter() {
            var firstField = queryBuilderFields.length ? String(queryBuilderFields[0].id || queryBuilderFields[0].field) : '';
            return {
                field: firstField,
                operator: 'equals',
                value: ''
            };
        }

        function createDefaultSort() {
            var firstField = queryBuilderFields.length ? String(queryBuilderFields[0].id || queryBuilderFields[0].field) : '';
            return {
                field: firstField,
                direction: 'ASC'
            };
        }

        function loadSavedViews() {
            if (!window.localStorage) {
                return [];
            }

            try {
                var raw = window.localStorage.getItem(viewStorageKey);
                if (!raw) {
                    return [];
                }
                var parsed = JSON.parse(raw);
                return Array.isArray(parsed) ? parsed : [];
            } catch (error) {
                return [];
            }
        }

        function persistSavedViews(views) {
            if (!window.localStorage) {
                return;
            }

            try {
                window.localStorage.setItem(viewStorageKey, JSON.stringify(views));
            } catch (error) {
                if (window.console && console.warn) {
                    console.warn('FastCrud: failed to persist saved views', error);
                }
            }
        }

        function updateViewDeleteState() {
            if (!deleteViewButton) {
                return;
            }

            if (!viewSelect || !viewSelect.val()) {
                deleteViewButton.prop('disabled', true);
            } else {
                deleteViewButton.prop('disabled', false);
            }
        }

        function renderSavedViewsSelect() {
            if (!viewSelect || !viewSelect.length) {
                return;
            }

            var current = viewSelect.val() || '';
            viewSelect.empty();
            viewSelect.append($('<option></option>').attr('value', '').text(t('default_view', 'Default view')));
            savedViews.forEach(function(view) {
                if (!view || typeof view.name !== 'string') {
                    return;
                }
                var option = $('<option></option>').attr('value', view.name).text(view.name);
                viewSelect.append(option);
            });

            if (current && savedViews.some(function(view) { return view && view.name === current; })) {
                viewSelect.val(current);
            } else if (queryBuilderState.activeView) {
                viewSelect.val(queryBuilderState.activeView);
            } else {
                viewSelect.val('');
            }

            updateViewDeleteState();
        }

        function updateQueryBuilderBadge() {
            if (!filtersButtonBadge) {
                return;
            }

            var count = queryBuilderState.filters.filter(function(filter) {
                return filter && filter.field;
            }).length;

            if (count > 0) {
                filtersButtonBadge.text(count).removeClass('d-none');
            } else {
                filtersButtonBadge.text('').addClass('d-none');
            }
        }

        function syncSortStateFromOrderBy() {
            queryBuilderState.sorts = deepClone(orderBy || []);
        }

        function syncQueryBuilderToConfig() {
            if (formOnlyMode) {
                return;
            }

            var filtersPayload = [];
            queryBuilderState.filters.forEach(function(filter) {
                var payload = buildFilterPayloadForState(filter);
                if (payload) {
                    filtersPayload.push(payload);
                }
            });

            if (!clientConfig.query_builder || typeof clientConfig.query_builder !== 'object') {
                clientConfig.query_builder = {};
            }

            clientConfig.query_builder.filters = filtersPayload;
            clientConfig.query_builder.logic = queryBuilderState.logic === 'OR' ? 'OR' : 'AND';
            clientConfig.query_builder.sorts = deepClone(orderBy || []);
            if (queryBuilderState.activeViewDirty) {
                clientConfig.query_builder.active_view = null;
            } else {
                clientConfig.query_builder.active_view = queryBuilderState.activeView || null;
            }
        }

        function hydrateQueryBuilderFromMeta(metaQB) {
            if (!metaQB || typeof metaQB !== 'object') {
                return;
            }

            if (Array.isArray(metaQB.fields)) {
                queryBuilderFields = deepClone(metaQB.fields);
                queryBuilderFieldMap = {};
                queryBuilderFields.forEach(function(field) {
                    if (!field || typeof field !== 'object') {
                        return;
                    }
                    var id = field.id || field.field;
                    if (!id) {
                        return;
                    }
                    var key = String(id);
                    var normalizedField = $.extend(true, {}, field);
                    normalizedField.id = key;
                    normalizedField.field = key;
                    if (typeof normalizedField.options === 'object' && !Array.isArray(normalizedField.options)) {
                        var optionsArray = [];
                        Object.keys(normalizedField.options).forEach(function(optionKey) {
                            optionsArray.push({
                                value: optionKey,
                                label: normalizedField.options[optionKey]
                            });
                        });
                        normalizedField.options = optionsArray;
                    }
                    queryBuilderFieldMap[key] = normalizedField;
                });
            }

            if (Array.isArray(metaQB.operators)) {
                queryBuilderOperators = deepClone(metaQB.operators);
                queryBuilderOperatorMap = {};
                queryBuilderOperators.forEach(function(operator) {
                    if (!operator || typeof operator !== 'object' || !operator.value) {
                        return;
                    }
                    queryBuilderOperatorMap[String(operator.value)] = operator;
                });
            }

            if (Array.isArray(metaQB.filters)) {
                queryBuilderState.filters = metaQB.filters.map(function(filter) {
                    var field = filter && typeof filter.field === 'string' ? filter.field : '';
                    var operator = filter && typeof filter.operator === 'string' ? filter.operator : 'equals';
                    var rawValue = '';
                    if (filter && Array.isArray(filter.value)) {
                        rawValue = filter.value.join(', ');
                    } else if (filter && filter.value !== null && typeof filter.value !== 'undefined') {
                        rawValue = String(filter.value);
                    }
                    return {
                        field: field,
                        operator: operator,
                        value: rawValue
                    };
                });
            }

            queryBuilderState.logic = metaQB.logic === 'OR' ? 'OR' : 'AND';

            if (Array.isArray(metaQB.sorts)) {
                queryBuilderState.sorts = deepClone(metaQB.sorts);
                orderBy = deepClone(metaQB.sorts);
                clientConfig.order_by = deepClone(metaQB.sorts);
            }

            if (typeof metaQB.active_view === 'string' && metaQB.active_view.trim() !== '') {
                queryBuilderState.activeView = metaQB.active_view.trim();
            } else {
                queryBuilderState.activeView = null;
            }
            queryBuilderState.activeViewDirty = false;

            renderSavedViewsSelect();
            updateQueryBuilderBadge();
        }

        function ensureQueryBuilderModal() {
            if (queryBuilderModal && queryBuilderModal.length) {
                return;
            }

            queryBuilderModal = $('#' + tableId + '-query-builder');
            if (queryBuilderModal && queryBuilderModal.length && !queryBuilderModal.data('fastcrudPortal')) {
                queryBuilderModal.appendTo(document.body);
                queryBuilderModal.data('fastcrudPortal', 1);
            }
            queryBuilderFiltersContainer = $('#' + tableId + '-qb-filters');
            queryBuilderSortsContainer = $('#' + tableId + '-qb-sorts');
            queryBuilderLogicSelect = $('#' + tableId + '-qb-logic');

            if (queryBuilderModal && queryBuilderModal.length && !queryBuilderModal.data('fastcrudBound')) {
                var applyBtn = $('#' + tableId + '-qb-apply');
                var clearBtn = $('#' + tableId + '-qb-clear');
                var saveBtn = $('#' + tableId + '-qb-save');

                if (applyBtn.length) {
                    applyBtn.on('click', function(event) {
                        event.preventDefault();
                        applyQueryBuilderSelections();
                    });
                }

                if (clearBtn.length) {
                    clearBtn.on('click', function(event) {
                        event.preventDefault();
                        clearQueryBuilderState();
                    });
                }

                if (saveBtn.length) {
                    saveBtn.on('click', function(event) {
                        event.preventDefault();
                        saveCurrentView();
                    });
                }

                if (queryBuilderLogicSelect && queryBuilderLogicSelect.length) {
                    queryBuilderLogicSelect.on('change', function() {
                        var val = $(this).val() === 'OR' ? 'OR' : 'AND';
                        if (queryBuilderState.logic !== val) {
                            queryBuilderState.logic = val;
                            markFiltersDirty();
                        }
                    });
                }

                queryBuilderModal.data('fastcrudBound', 1);
            }
        }

        function renderQueryBuilderFilters() {
            ensureQueryBuilderModal();
            if (!queryBuilderFiltersContainer || !queryBuilderFiltersContainer.length) {
            return;
        }

        if (select2Enabled) {
            destroySelect2(queryBuilderFiltersContainer);
        }

        queryBuilderFiltersContainer.empty();

            if (!queryBuilderFields.length) {
                queryBuilderFiltersContainer.append('<div class="text-muted small">No filterable fields available.</div>');
                return;
            }

            queryBuilderState.filters.forEach(function(filter, index) {
                var currentFilter = filter || { field: '', operator: 'equals', value: '' };
                var row = $('<div class="row g-2 align-items-center mb-2 fastcrud-qb-filter-row"></div>');

                var fieldCol = $('<div class="col-4"></div>');
                var fieldSelect = $('<select class="form-select form-select-sm" data-fastcrud-type="select"></select>');
                queryBuilderFields.forEach(function(field) {
                    if (!field || !field.id) {
                        return;
                    }
                    var option = $('<option></option>').attr('value', field.id).text(field.label || field.id);
                    fieldSelect.append(option);
                });
                fieldSelect.val(currentFilter.field);
                fieldSelect.on('change', function() {
                    var newField = $(this).val() ? String($(this).val()) : '';
                    queryBuilderState.filters[index] = {
                        field: newField,
                        operator: 'equals',
                        value: ''
                    };
                    markFiltersDirty();
                    renderQueryBuilderFilters();
                    updateQueryBuilderBadge();
                });
                fieldCol.append(fieldSelect);
                row.append(fieldCol);

                var operatorCol = $('<div class="col-3"></div>');
                var operatorSelect = $('<select class="form-select form-select-sm"></select>');
                var operatorChoices = getOperatorsForField(currentFilter.field);
                if (operatorChoices.indexOf(currentFilter.operator) === -1) {
                    currentFilter.operator = operatorChoices[0] || 'equals';
                    queryBuilderState.filters[index].operator = currentFilter.operator;
                }
                operatorChoices.forEach(function(op) {
                    var info = getOperatorInfo(op);
                    var label = info && info.label ? info.label : op;
                    operatorSelect.append($('<option></option>').attr('value', op).text(label));
                });
                operatorSelect.val(currentFilter.operator);
                operatorSelect.on('change', function() {
                    var newOperator = $(this).val() ? String($(this).val()) : 'equals';
                    queryBuilderState.filters[index].operator = newOperator;
                    if (newOperator === 'empty' || newOperator === 'not_empty') {
                        queryBuilderState.filters[index].value = '';
                    }
                    markFiltersDirty();
                    renderQueryBuilderFilters();
                    updateQueryBuilderBadge();
                });
                operatorCol.append(operatorSelect);
                row.append(operatorCol);

                var valueCol = $('<div class="col-4"></div>');
                var operatorInfo = getOperatorInfo(currentFilter.operator) || { requires_value: true, multi: false };
                var fieldInfo = getFieldInfo(currentFilter.field) || {};
                var fieldOptions = Array.isArray(fieldInfo.options) ? fieldInfo.options : [];
                var needsValue = operatorInfo.requires_value !== false;
                var optionOperators = ['equals', 'not_equals', 'in', 'not_in'];
                var supportsOptionSelect = needsValue && fieldOptions.length && optionOperators.indexOf(currentFilter.operator) !== -1;
                var valueInput;

                if (!needsValue) {
                    valueInput = $('<input type="text" class="form-control form-control-sm" disabled />');
                } else if (supportsOptionSelect) {
                    valueInput = $('<select class="form-select form-select-sm"></select>');
                    if (operatorInfo.multi) {
                        valueInput.attr('multiple', 'multiple');
                        valueInput.attr('data-fastcrud-type', 'multiselect');
                    } else {
                        valueInput.append('<option value="">Select…</option>');
                        valueInput.attr('data-fastcrud-type', 'select');
                        valueInput.attr('data-placeholder', 'Select…');
                    }

                    fieldOptions.forEach(function(option) {
                        if (!option || typeof option !== 'object') {
                            return;
                        }
                        var opt = $('<option></option>').attr('value', option.value).text(option.label || option.value);
                        valueInput.append(opt);
                    });

                    if (operatorInfo.multi) {
                        var selectedValues = [];
                        if (currentFilter.value && typeof currentFilter.value === 'string') {
                            selectedValues = currentFilter.value.split(',').map(function(piece) {
                                return piece.trim();
                            }).filter(function(piece) {
                                return piece.length > 0;
                            });
                        }
                        valueInput.val(selectedValues);
                    } else {
                        valueInput.val(currentFilter.value || '');
                    }

                    valueInput.on('change', function() {
                        if (operatorInfo.multi) {
                            var selected = $(this).val();
                            if (Array.isArray(selected)) {
                                var joined = selected.map(function(piece) {
                                    return String(piece).trim();
                                }).filter(function(piece) {
                                    return piece.length > 0;
                                }).join(', ');
                                queryBuilderState.filters[index].value = joined;
                            } else {
                                queryBuilderState.filters[index].value = '';
                            }
                        } else {
                            var selectedValue = $(this).val();
                            queryBuilderState.filters[index].value = selectedValue ? String(selectedValue) : '';
                        }
                        markFiltersDirty();
                    });
                } else if (fieldInfo.type === 'boolean') {
                    valueInput = $('<select class="form-select form-select-sm" data-fastcrud-type="select"></select>');
                    valueInput.append('<option value="">Select…</option>');
                    valueInput.append('<option value="1">True</option>');
                    valueInput.append('<option value="0">False</option>');
                    valueInput.val(currentFilter.value || '');
                    valueInput.on('change', function() {
                        queryBuilderState.filters[index].value = $(this).val();
                        markFiltersDirty();
                    });
                } else {
                    valueInput = $('<input type="text" class="form-control form-control-sm" />');
                    if (operatorInfo.multi) {
                        valueInput.attr('placeholder', 'Value 1, Value 2');
                    }
                    valueInput.val(currentFilter.value || '');
                    valueInput.on('input change', function() {
                        queryBuilderState.filters[index].value = $(this).val();
                        markFiltersDirty();
                    });
                }

                if (!needsValue) {
                    valueInput.val('');
                }

                if (currentFilter._invalid) {
                    valueInput.addClass('is-invalid');
                    delete queryBuilderState.filters[index]._invalid;
                }

                valueCol.append(valueInput);
                row.append(valueCol);

                var actionsCol = $('<div class="col-1 text-end"></div>');
                var removeBtn = $('<button type="button" class="btn btn-sm btn-outline-danger" aria-label="Remove filter"></button>');
                removeBtn.append($('<i aria-hidden="true"></i>').addClass(dismissIconClass));
                removeBtn.on('click', function() {
                    queryBuilderState.filters.splice(index, 1);
                    markFiltersDirty();
                    renderQueryBuilderFilters();
                    updateQueryBuilderBadge();
                });
                actionsCol.append(removeBtn);
                row.append(actionsCol);

                queryBuilderFiltersContainer.append(row);
            });

            var addBtnWrapper = $('<div class="mt-2"></div>');
            var addBtn = $('<button type="button" class="btn btn-sm btn-outline-primary">Add Filter</button>');
            addBtn.on('click', function() {
                queryBuilderState.filters.push(createDefaultFilter());
                markFiltersDirty();
                renderQueryBuilderFilters();
                updateQueryBuilderBadge();
            });
            addBtnWrapper.append(addBtn);
            queryBuilderFiltersContainer.append(addBtnWrapper);

            if (select2Enabled) {
                initializeSelect2(queryBuilderFiltersContainer);
            }
        }

        function renderQueryBuilderSorts() {
            ensureQueryBuilderModal();
            if (!queryBuilderSortsContainer || !queryBuilderSortsContainer.length) {
                return;
            }

            if (select2Enabled) {
                destroySelect2(queryBuilderSortsContainer);
            }

            queryBuilderSortsContainer.empty();

            queryBuilderState.sorts.forEach(function(sort, index) {
                var currentSort = sort || { field: '', direction: 'ASC' };
                var row = $('<div class="row g-2 align-items-center mb-2 fastcrud-qb-sort-row"></div>');

                var fieldCol = $('<div class="col-6"></div>');
                var fieldSelect = $('<select class="form-select form-select-sm" data-fastcrud-type="select"></select>');
                queryBuilderFields.forEach(function(field) {
                    if (!field || !field.id) {
                        return;
                    }
                    var option = $('<option></option>').attr('value', field.id).text(field.label || field.id);
                    fieldSelect.append(option);
                });
                fieldSelect.val(currentSort.field);
                fieldSelect.on('change', function() {
                    queryBuilderState.sorts[index].field = $(this).val() ? String($(this).val()) : '';
                    markFiltersDirty();
                });
                fieldCol.append(fieldSelect);
                row.append(fieldCol);

                var directionCol = $('<div class="col-4"></div>');
                var directionSelect = $('<select class="form-select form-select-sm"></select>');
                directionSelect.append('<option value="ASC">Ascending</option>');
                directionSelect.append('<option value="DESC">Descending</option>');
                directionSelect.val((currentSort.direction || 'ASC').toUpperCase() === 'DESC' ? 'DESC' : 'ASC');
                directionSelect.on('change', function() {
                    queryBuilderState.sorts[index].direction = $(this).val() ? String($(this).val()).toUpperCase() : 'ASC';
                    markFiltersDirty();
                });
                directionCol.append(directionSelect);
                row.append(directionCol);

                var actionsCol = $('<div class="col-2 text-end"></div>');
                var removeBtn = $('<button type="button" class="btn btn-sm btn-outline-danger" aria-label="Remove sort"></button>');
                removeBtn.append($('<i aria-hidden="true"></i>').addClass(dismissIconClass));
                removeBtn.on('click', function() {
                    queryBuilderState.sorts.splice(index, 1);
                    markFiltersDirty();
                    renderQueryBuilderSorts();
                });
                actionsCol.append(removeBtn);
                row.append(actionsCol);

                queryBuilderSortsContainer.append(row);
            });

            var addBtnWrapper = $('<div class="mt-2"></div>');
            var addBtn = $('<button type="button" class="btn btn-sm btn-outline-primary">Add Sort</button>');
            addBtn.on('click', function() {
                queryBuilderState.sorts.push(createDefaultSort());
                markFiltersDirty();
                renderQueryBuilderSorts();
            });
            addBtnWrapper.append(addBtn);
            queryBuilderSortsContainer.append(addBtnWrapper);

            if (select2Enabled) {
                initializeSelect2(queryBuilderSortsContainer);
            }
        }

        function refreshQueryBuilderModal() {
            ensureQueryBuilderModal();
            renderQueryBuilderFilters();
            renderQueryBuilderSorts();
            if (queryBuilderLogicSelect && queryBuilderLogicSelect.length) {
                queryBuilderLogicSelect.val(queryBuilderState.logic === 'OR' ? 'OR' : 'AND');
            }
        }

        function openQueryBuilderModal() {
            if (formOnlyMode || !filtersEnabled) {
                return;
            }

            ensureQueryBuilderModal();
            syncSortStateFromOrderBy();
            refreshQueryBuilderModal();

            if (queryBuilderModal && queryBuilderModal.length) {
                if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                    bootstrap.Modal.getOrCreateInstance(queryBuilderModal.get(0)).show();
                } else {
                    queryBuilderModal.removeClass('d-none').show();
                }
            }
        }

        function closeQueryBuilderModal() {
            if (queryBuilderModal && queryBuilderModal.length) {
                if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                    var instance = bootstrap.Modal.getInstance(queryBuilderModal.get(0));
                    if (instance) {
                        instance.hide();
                    }
                } else {
                    queryBuilderModal.hide();
                }
            }
        }

        function clearQueryBuilderState() {
            queryBuilderState.filters = [];
            queryBuilderState.logic = 'AND';
            queryBuilderState.sorts = [];
            queryBuilderState.activeView = null;
            queryBuilderState.activeViewDirty = false;
            if (viewSelect) {
                viewSelect.val('');
                updateViewDeleteState();
            }
            refreshQueryBuilderModal();
            updateQueryBuilderBadge();
        }

        function applyQueryBuilderSelections() {
            var validFilters = [];
            var hasInvalid = false;

            queryBuilderState.filters.forEach(function(filter, index) {
                var payload = buildFilterPayloadForState(filter);
                if (!payload) {
                    queryBuilderState.filters[index]._invalid = true;
                    hasInvalid = true;
                } else {
                    validFilters.push(payload);
                }
            });

            if (hasInvalid) {
                refreshQueryBuilderModal();
                updateQueryBuilderBadge();
                alert('Please complete all filter values before applying.');
                return;
            }

            orderBy = deepClone(queryBuilderState.sorts || []);
            clientConfig.order_by = deepClone(orderBy);

            markFiltersDirty();
            syncQueryBuilderToConfig();
            updateQueryBuilderBadge();
            updateSortIndicators();
            closeQueryBuilderModal();
            loadTableData(1);
        }

        function saveCurrentView() {
            var defaultName = '';
            if (typeof queryBuilderState.activeView === 'string') {
                var activeView = queryBuilderState.activeView.trim();
                if (activeView.length) {
                    var exists = savedViews.some(function(view) {
                        return view && view.name === activeView;
                    });
                    if (exists) {
                        defaultName = activeView;
                    }
                }
            }

            var name = window.prompt('Enter a name for this view:', defaultName);
            if (!name) {
                return;
            }
            name = name.trim();
            if (!name.length) {
                return;
            }

            var existingIndex = savedViews.findIndex(function(view) {
                return view && view.name === name;
            });

            var viewDefinition = {
                name: name,
                filters: deepClone(queryBuilderState.filters || []),
                logic: queryBuilderState.logic === 'OR' ? 'OR' : 'AND',
                sorts: deepClone(queryBuilderState.sorts || []),
                searchTerm: currentSearchTerm || '',
                searchColumn: currentSearchColumn || null
            };

            if (existingIndex >= 0) {
                savedViews[existingIndex] = viewDefinition;
            } else {
                savedViews.push(viewDefinition);
            }

            persistSavedViews(savedViews);
            queryBuilderState.activeView = name;
            queryBuilderState.activeViewDirty = false;
            renderSavedViewsSelect();
            updateQueryBuilderBadge();
        }

        function deleteCurrentView() {
            if (!viewSelect) {
                return;
            }
            var name = viewSelect.val();
            if (!name) {
                return;
            }
            if (!window.confirm('Delete view "' + name + '"?')) {
                return;
            }
            savedViews = savedViews.filter(function(view) {
                return view && view.name !== name;
            });
            persistSavedViews(savedViews);
            queryBuilderState.activeView = null;
            queryBuilderState.activeViewDirty = false;
            viewSelect.val('');
            updateViewDeleteState();
            renderSavedViewsSelect();
        }

        function applySavedViewByName(name) {
            if (!name) {
                queryBuilderState.activeView = null;
                queryBuilderState.activeViewDirty = false;
                queryBuilderState.filters = [];
                queryBuilderState.logic = 'AND';
                queryBuilderState.sorts = [];
                currentSearchTerm = '';
                currentSearchColumn = null;
                if (searchInput) {
                    searchInput.val('');
                }
                if (searchSelect) {
                    searchSelect.val('');
                }
                orderBy = [];
                clientConfig.order_by = [];
                syncQueryBuilderToConfig();
                updateSortIndicators();
                updateQueryBuilderBadge();
                loadTableData(1);
                return;
            }

            var view = savedViews.find(function(entry) {
                return entry && entry.name === name;
            });

            if (!view) {
                return;
            }

            queryBuilderState.activeView = view.name;
            queryBuilderState.activeViewDirty = false;
            queryBuilderState.filters = deepClone(view.filters || []);
            queryBuilderState.logic = view.logic === 'OR' ? 'OR' : 'AND';
            queryBuilderState.sorts = deepClone(view.sorts || []);

            if (viewSelect) {
                viewSelect.val(view.name);
                updateViewDeleteState();
            }

            currentSearchTerm = view.searchTerm || '';
            currentSearchColumn = view.searchColumn || null;

            if (searchInput) {
                searchInput.val(currentSearchTerm);
            }

            if (searchSelect) {
                searchSelect.val(currentSearchColumn || '');
            }

            orderBy = deepClone(queryBuilderState.sorts || []);
            clientConfig.order_by = deepClone(orderBy);
            syncQueryBuilderToConfig();
            updateSortIndicators();
            updateQueryBuilderBadge();
            loadTableData(1);
        }

        function markFiltersDirty() {
            if (queryBuilderState.activeView) {
                queryBuilderState.activeViewDirty = true;
            } else {
                queryBuilderState.activeViewDirty = false;
            }

            if (viewSelect) {
                viewSelect.val(queryBuilderState.activeView || '');
                updateViewDeleteState();
            }
        }

        savedViews = loadSavedViews();
        var addEnabled = true;
        var viewEnabled = true;
        var editEnabled = true;
        var deleteEnabled = true;
        var duplicateEnabled = false;
        var deleteConfirm = true;
        var sortDisabled = {};
        var inlineEditFields = {};
        var batchDeleteEnabled = false;
        var batchDeleteButton = null;
        var selectAllCheckbox = null;
        var selectedRows = {};
        var bulkActions = [];
        var allowBatchDeleteButton = false;
        var formConfig = {
            layouts: {},
            default_tabs: {},
            behaviours: {},
            labels: {},
            all_columns: [],
            sections: {},
            templates: {}
        };
        var formTemplates = {};
        var currentFieldErrors = {};
        var lastSubmitAction = null;
        var tableHasRendered = false;
        var activeFetchRequest = null;
        var fetchSequence = 0;
        var metadataHash = null;
        var disposed = false;
        var tableViewportCache = null;

        function ensureLoadingStyles() {
            var styleId = 'fastcrud-loading-style';
            if (document.getElementById(styleId)) {
                return;
            }
            if (!document.head) {
                return;
            }
            var css = [
                '.fastcrud-table-container{position:relative;}',
                '.fastcrud-table-container>table{transition:opacity .18s ease-in-out,filter .18s ease-in-out;}',
                '.fastcrud-table-container.fastcrud-loading-active>table{opacity:0.45;filter:blur(1px);}',
                '.fastcrud-loading-overlay{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;padding:1rem;background:rgba(255,255,255,0.7);backdrop-filter:blur(2px);opacity:0;pointer-events:none;transition:opacity .18s ease-in-out;z-index:5;}',
                '.fastcrud-loading-overlay.fastcrud-visible{opacity:1;pointer-events:auto;}',
                '.fastcrud-loading-message{display:inline-flex;align-items:center;gap:0.5rem;font-weight:500;color:var(--bs-body-color,#212529);}',
                '.fastcrud-loading-placeholder{vertical-align:middle;}',
                '.fastcrud-loading-placeholder .spinner-border{width:1rem;height:1rem;}',
                '[data-bs-theme=dark] .fastcrud-loading-overlay{background:rgba(15,23,42,0.55);}',
                '@media (prefers-reduced-motion: reduce){.fastcrud-table-container>table{transition:none;filter:none;}.fastcrud-table-container.fastcrud-loading-active>table{opacity:1;}.fastcrud-loading-overlay{transition:none;}}'
            ].join('');
            var styleEl = document.createElement('style');
            styleEl.id = styleId;
            styleEl.type = 'text/css';
            styleEl.appendChild(document.createTextNode(css));
            document.head.appendChild(styleEl);
        }

        function getTableViewport() {
            if (tableViewportCache && tableViewportCache.length) {
                return tableViewportCache;
            }
            var viewport = container.find('.fastcrud-table-container').first();
            if (!viewport.length) {
                viewport = container.find('.table-responsive').first();
            }
            tableViewportCache = viewport;
            return viewport;
        }

        function ensureLoadingOverlay(viewport) {
            var target = viewport && viewport.length ? viewport : getTableViewport();
            if (!target || !target.length) {
                return $();
            }
            var overlay = target.children('.fastcrud-loading-overlay');
            if (!overlay.length) {
                overlay = $('<div class="fastcrud-loading-overlay" aria-live="polite"></div>');
                var message = $('<div class="fastcrud-loading-message"></div>');
                var spinner = $('<span class="spinner-border spinner-border-sm" role="status"></span>');
                spinner.append($('<span class="visually-hidden"></span>').text(t('loading', 'Loading...')));
                var text = $('<span class="fastcrud-loading-text"></span>').text(t('loading', 'Loading...'));
                message.append(spinner).append(text);
                overlay.append(message);
                target.append(overlay);
            }
            return overlay;
        }

        function updateLoadingMessage(message) {
            var overlay = ensureLoadingOverlay();
            if (!overlay.length) {
                return;
            }
            var text = overlay.find('.fastcrud-loading-text');
            if (!text.length) {
                return;
            }
            text.text(message || t('loading', 'Loading...'));
        }

        function beginLoadingState() {
            var viewport = getTableViewport();
            if (!viewport.length) {
                return;
            }
            var overlay = ensureLoadingOverlay(viewport);
            viewport.addClass('fastcrud-loading-active');
            var raf = window.requestAnimationFrame || function(handler) {
                return window.setTimeout(handler, 16);
            };
            raf(function() {
                overlay.addClass('fastcrud-visible');
            });
        }

        function endLoadingState() {
            var viewport = getTableViewport();
            if (!viewport.length) {
                return;
            }
            var overlay = viewport.children('.fastcrud-loading-overlay');
            overlay.removeClass('fastcrud-visible');
            viewport.removeClass('fastcrud-loading-active');
        }

        ensureLoadingStyles();
        function getFormTemplate(mode) {
            if (!mode) {
                return null;
            }

            var templates = formTemplates && typeof formTemplates === 'object' ? formTemplates : {};
            if (!templates) {
                return null;
            }

            var key = String(mode).toLowerCase();
            if (!Object.prototype.hasOwnProperty.call(templates, key)) {
                return null;
            }

            var template = templates[key];
            if (!template || typeof template !== 'object') {
                return null;
            }

            return deepClone(template);
        }

        function ensureRowColumns(row) {
            var output = row && typeof row === 'object' ? row : {};
            var sourceColumns = baseColumns.length ? baseColumns : columnsCache;
            sourceColumns.forEach(function(column) {
                if (typeof column !== 'string') {
                    return;
                }
                if (!Object.prototype.hasOwnProperty.call(output, column)) {
                    output[column] = null;
                }
            });

            return output;
        }
        // Cache for on-demand row fetches (keyed by tableId + '::' + pkCol + '::' + pkVal)
        var rowCache = {};
        var formOnlyMode = container.attr('data-fastcrud-form-only') === '1';
        var formDisplayMode = String(container.attr('data-fastcrud-form-display-mode') || clientConfig.form_display_mode || 'offcanvas').toLowerCase();
        if (['offcanvas', 'modal', 'side', 'inline'].indexOf(formDisplayMode) === -1) {
            formDisplayMode = 'offcanvas';
        }
        if (formOnlyMode) {
            formDisplayMode = 'inline';
        }
        var initialMode = String(container.attr('data-fastcrud-initial-mode') || '').toLowerCase();
        if (['create', 'edit', 'view'].indexOf(initialMode) === -1) {
            initialMode = '';
        }
        var initialPrimaryKeyValue = container.attr('data-fastcrud-initial-primary');
        var initialPrimaryKeyColumn = container.attr('data-fastcrud-initial-primary-column') || '';
        if (!primaryKeyColumn && initialPrimaryKeyColumn) {
            primaryKeyColumn = initialPrimaryKeyColumn;
        }
        if (typeof initialPrimaryKeyValue === 'string' && initialPrimaryKeyValue.length) {
            var trimmedInitial = initialPrimaryKeyValue.trim();
            var firstChar = trimmedInitial.charAt(0);
            var lastChar = trimmedInitial.charAt(trimmedInitial.length - 1);
            if ((firstChar === '{' && lastChar === '}') || (firstChar === '[' && lastChar === ']')) {
                try {
                    initialPrimaryKeyValue = JSON.parse(trimmedInitial);
                } catch (error) {
                    // Leave as-is if parsing fails
                }
            }
        }
        if (!primaryKeyColumn && typeof clientConfig.primary_key === 'string' && clientConfig.primary_key.length) {
            primaryKeyColumn = clientConfig.primary_key;
        }
        if (formOnlyMode) {
            container.addClass('fastcrud-form-only');

            if (clientConfig && typeof clientConfig === 'object') {
                var seedTableMeta = {};
                if (clientConfig.table_meta && typeof clientConfig.table_meta === 'object') {
                    try {
                        seedTableMeta = JSON.parse(JSON.stringify(clientConfig.table_meta));
                    } catch (error) {
                        seedTableMeta = $.extend(true, {}, clientConfig.table_meta);
                    }
                }

                if (!seedTableMeta || typeof seedTableMeta !== 'object') {
                    seedTableMeta = {};
                }

                if (!Object.prototype.hasOwnProperty.call(seedTableMeta, 'bulk_actions') || !Array.isArray(seedTableMeta.bulk_actions)) {
                    seedTableMeta.bulk_actions = [];
                }
                ['add','view','edit','delete'].forEach(function(flag){
                    if (!Object.prototype.hasOwnProperty.call(seedTableMeta, flag)) {
                        seedTableMeta[flag] = true;
                    }
                });
                if (!Object.prototype.hasOwnProperty.call(seedTableMeta, 'duplicate')) {
                    seedTableMeta.duplicate = false;
                }
                if (!Object.prototype.hasOwnProperty.call(seedTableMeta, 'delete_confirm')) {
                    seedTableMeta.delete_confirm = true;
                }
                if (!Object.prototype.hasOwnProperty.call(seedTableMeta, 'batch_delete')) {
                    seedTableMeta.batch_delete = false;
                }
                if (!Object.prototype.hasOwnProperty.call(seedTableMeta, 'batch_delete_button')) {
                    seedTableMeta.batch_delete_button = false;
                }
                if (!Object.prototype.hasOwnProperty.call(seedTableMeta, 'export_csv')) {
                    seedTableMeta.export_csv = false;
                }

                var seedMeta = {
                    columns: Array.isArray(clientConfig.columns) ? clientConfig.columns.slice() : [],
                    primary_key: clientConfig.primary_key || null,
                    form: clientConfig.form || {},
                    inline_edit: Array.isArray(clientConfig.inline_edit) ? clientConfig.inline_edit.slice() : (clientConfig.inline_edit || []),
                    table: seedTableMeta,
                    compact_pagination: !!clientConfig.compact_pagination,
                    sort_disabled: Array.isArray(clientConfig.sort_disabled) ? clientConfig.sort_disabled.slice() : [],
                    limit_options: Array.isArray(clientConfig.limit_options) ? clientConfig.limit_options.slice() : [],
                    default_limit: typeof clientConfig.limit_default !== 'undefined' ? clientConfig.limit_default : null,
                    search: {
                        columns: Array.isArray(clientConfig.search_columns) ? clientConfig.search_columns.slice() : [],
                        'default': clientConfig.search_default || null,
                        available: Array.isArray(clientConfig.search_columns) ? clientConfig.search_columns.slice() : [],
                        hidden: !!clientConfig.hide_search
                    },
                    nested_tables: Array.isArray(clientConfig.nested_tables) ? clientConfig.nested_tables.slice() : [],
                    link_buttons: Array.isArray(clientConfig.link_buttons) ? clientConfig.link_buttons.slice() : [],
                    multi_link_buttons: Array.isArray(clientConfig.multi_link_buttons) ? clientConfig.multi_link_buttons.slice() : [],
                    action_button_sequence: Array.isArray(clientConfig.action_button_sequence)
                        ? clientConfig.action_button_sequence.slice()
                        : [],
                    soft_delete: clientConfig.soft_delete || null,
                    labels: clientConfig.column_labels || {},
                    column_classes: clientConfig.column_classes || {},
                    column_widths: clientConfig.column_widths || {},
                    order_by: Array.isArray(clientConfig.order_by) ? clientConfig.order_by.slice() : [],
                    summaries: Array.isArray(clientConfig.column_summaries) ? clientConfig.column_summaries.slice() : []
                };

                if (seedMeta.form && typeof seedMeta.form === 'object') {
                    try {
                        seedMeta.form = JSON.parse(JSON.stringify(seedMeta.form));
                    } catch (error) {
                        seedMeta.form = $.extend(true, {}, seedMeta.form);
                    }
                } else {
                    seedMeta.form = {};
                }

                if ((!seedMeta.columns || !seedMeta.columns.length) && seedMeta.form && Array.isArray(seedMeta.form.all_columns)) {
                    seedMeta.columns = seedMeta.form.all_columns.slice();
                }

                if (!seedMeta.columns || !seedMeta.columns.length) {
                    seedMeta.columns = [];
                }

                applyMeta(seedMeta);
            }
        }

        function getStyleClass(key, fallback) {
            var value = '';
            if (styleDefaults && Object.prototype.hasOwnProperty.call(styleDefaults, key)) {
                value = String(styleDefaults[key] || '');
            }
            value = value.trim();
            return value || fallback;
        }

        function clearRowHighlight() {
            try {
                table.find('tbody tr.fastcrud-editing').each(function() {
                    var trEl = $(this);
                    var had = trEl.data('fastcrudHadClass');
                    if (had !== 1 && had !== '1') { trEl.removeClass(editViewHighlightClass); }
                    trEl.removeClass('fastcrud-editing').removeData('fastcrudHadClass');
                });
            } catch (e) {}
        }

        function resolvePrimaryKeyColumn() {
            if (primaryKeyColumn && String(primaryKeyColumn).length) {
                return primaryKeyColumn;
            }

            if (initialPrimaryKeyColumn && String(initialPrimaryKeyColumn).length) {
                primaryKeyColumn = initialPrimaryKeyColumn;
                return primaryKeyColumn;
            }

            if (clientConfig && typeof clientConfig.primary_key === 'string' && clientConfig.primary_key.length) {
                primaryKeyColumn = clientConfig.primary_key;
                return primaryKeyColumn;
            }

            return null;
        }

        function highlightRow(tr) {
            if (!tr || !tr.length) {
                clearRowHighlight();
                return;
            }

            try {
                clearRowHighlight();
                var parts = String(editViewHighlightClass || '').split(/\s+/).filter(function(s){ return s.length > 0; });
                var hasAll = true;
                for (var i = 0; i < parts.length; i++) {
                    if (!tr.hasClass(parts[i])) { hasAll = false; break; }
                }
                var alreadyHas = hasAll ? 1 : 0;
                tr.data('fastcrudHadClass', alreadyHas);
                tr.addClass('fastcrud-editing');
                if (!alreadyHas) { tr.addClass(editViewHighlightClass); }
            } catch (e) {}
        }

        var toolbar = $('#' + tableId + '-toolbar');
        var rangeDisplay = $('#' + tableId + '-range');
        var metaContainer = $('#' + tableId + '-meta');
        var searchGroup = null;
        var searchInput = null;
        var searchSelect = null;
        var searchButton = null;
        var clearButton = null;

        selectAllCheckbox = table.find('thead .fastcrud-select-all');
        if (selectAllCheckbox.length) {
            selectAllCheckbox.on('change', function() {
                if (!batchDeleteEnabled || !deleteEnabled) {
                    $(this).prop('checked', false).prop('indeterminate', false);
                    return;
                }

                var shouldSelect = $(this).is(':checked');
                toggleSelectAll(shouldSelect);
            });
        }

        var editFormId = tableId + '-edit-form';
        var editForm = $('#' + editFormId);
        var editFieldsContainer = $('#' + tableId + '-edit-fields');
        var editError = $('#' + tableId + '-edit-error');
        var editSuccess = $('#' + tableId + '-edit-success');
        var editLabel = $('#' + tableId + '-edit-label');
        var editOffcanvasElement = $('#' + tableId + '-edit-panel');
        moveOffcanvasToBody(editOffcanvasElement);
        editForm.data('mode', 'edit');
        var editOffcanvasInstance = null;
        if (editOffcanvasElement.length) {
            var cleanupEditPanelWidgets = function() {
                cancelPendingWidgets(editFieldsContainer);
                destroyRichEditors(editFieldsContainer);
                destroySelect2(editFieldsContainer);
                destroyFilePonds(editFieldsContainer);
            };
            var clearEditPanelState = function() {
                clearRowHighlight();
            };

            if (formDisplayMode === 'modal') {
                editOffcanvasElement.on('hide.bs.modal', clearEditPanelState);
                editOffcanvasElement.on('hidden.bs.modal', cleanupEditPanelWidgets);
            } else if (formDisplayMode === 'offcanvas') {
                editOffcanvasElement.on('hide.bs.offcanvas', clearEditPanelState);
                editOffcanvasElement.on('hidden.bs.offcanvas', cleanupEditPanelWidgets);
            }

            editOffcanvasElement.on('click', '[data-fastcrud-side-close="1"]', function(event) {
                event.preventDefault();
                var instance = getEditOffcanvasInstance();
                if (instance && typeof instance.hide === 'function') {
                    instance.hide();
                }
            });
            editOffcanvasElement.on('click', '[data-fastcrud-inline-close="1"]', function(event) {
                event.preventDefault();
                var instance = getEditOffcanvasInstance();
                if (instance && typeof instance.hide === 'function') {
                    instance.hide();
                }
            });
        }

        var viewOffcanvasElement = $('#' + tableId + '-view-panel');
        moveOffcanvasToBody(viewOffcanvasElement);
        var viewContentContainer = $('#' + tableId + '-view-content');
        var viewEmptyNotice = $('#' + tableId + '-view-empty');
        var viewHeading = $('#' + tableId + '-view-label');
        var viewOffcanvasInstance = null;
        if (viewOffcanvasElement.length) {
            viewOffcanvasElement.on('hide.bs.offcanvas', function() {
                clearRowHighlight();
            });
            viewOffcanvasElement.on('click', '[data-fastcrud-side-close="1"]', function(event) {
                event.preventDefault();
                var instance = getViewOffcanvasInstance();
                if (instance && typeof instance.hide === 'function') {
                    instance.hide();
                }
            });
            viewOffcanvasElement.on('click', '[data-fastcrud-inline-close="1"]', function(event) {
                event.preventDefault();
                var instance = getViewOffcanvasInstance();
                if (instance && typeof instance.hide === 'function') {
                    instance.hide();
                }
            });
        }

        if (!formOnlyMode && container.length && !container.data('fastcrud-offcanvas-cleanup')) {
            container.data('fastcrud-offcanvas-cleanup', true);
            container.on('remove.fastcrudOffcanvasCleanup', function() {
                if (sidePanelHeightTimer !== null) {
                    window.clearTimeout(sidePanelHeightTimer);
                    sidePanelHeightTimer = null;
                }
                if (sidePanelResizeNamespace) {
                    $(window).off('resize' + sidePanelResizeNamespace);
                }
                var panels = [editOffcanvasElement, viewOffcanvasElement];
                panels.forEach(function(panel) {
                    if (!panel || !panel.length) {
                        return;
                    }
                    var node = panel.get(0);
                    if (node && typeof bootstrap !== 'undefined') {
                        var instance = null;
                        if (bootstrap.Offcanvas && typeof bootstrap.Offcanvas.getInstance === 'function') {
                            instance = bootstrap.Offcanvas.getInstance(node);
                        }
                        if (!instance && bootstrap.Modal && typeof bootstrap.Modal.getInstance === 'function') {
                            instance = bootstrap.Modal.getInstance(node);
                        }
                        if (instance && typeof instance.dispose === 'function') {
                            try {
                                instance.dispose();
                            } catch (error) {}
                        }
                    }
                    panel.remove();
                });
            });
        }
        var summaryFooter = $('#' + tableId + '-summary');

        // FilePond state and asset loader for image fields
        var filePondState = window.FastCrudFilePond || {};
        if (!filePondState.coreScriptUrl) {
            filePondState.coreScriptUrl = 'https://unpkg.com/filepond/dist/filepond.min.js';
        }
        if (!filePondState.coreStyleUrl) {
            filePondState.coreStyleUrl = 'https://unpkg.com/filepond/dist/filepond.min.css';
        }
        if (!filePondState.previewScriptUrl) {
            filePondState.previewScriptUrl = 'https://unpkg.com/filepond-plugin-image-preview/dist/filepond-plugin-image-preview.min.js';
        }
        if (!filePondState.previewStyleUrl) {
            filePondState.previewStyleUrl = 'https://unpkg.com/filepond-plugin-image-preview/dist/filepond-plugin-image-preview.min.css';
        }
        if (!filePondState.posterScriptUrl) {
            filePondState.posterScriptUrl = 'https://unpkg.com/filepond-plugin-file-poster/dist/filepond-plugin-file-poster.min.js';
        }
        if (!filePondState.posterStyleUrl) {
            filePondState.posterStyleUrl = 'https://unpkg.com/filepond-plugin-file-poster/dist/filepond-plugin-file-poster.min.css';
        }
        if (typeof filePondState.loaded === 'undefined') {
            filePondState.loaded = (typeof window.FilePond !== 'undefined');
        }
        if (typeof filePondState.loading === 'undefined') {
            filePondState.loading = false;
        }
        if (!Array.isArray(filePondState.queue)) {
            filePondState.queue = [];
        }
        window.FastCrudFilePond = filePondState;

        function getUploadPublicBase() {
            var base = String(richEditorConfig.upload_path || '/public/uploads');
            if (!/^https?:\/\//i.test(base) && base.charAt(0) !== '/') {
                base = '/' + base;
            }
            return base;
        }

        function joinPublicUrl(base, name) {
            if (!name) { return String(base || ''); }
            var b = String(base || '');
            if (b && b.charAt(b.length - 1) !== '/') { b += '/'; }
            var seg = String(name).replace(/^\/+/, '');
            return b + seg;
        }

        function toPublicUrl(value) {
            var v = String(value || '');
            if (!v) { return ''; }
            if (/^https?:\/\//i.test(v) || v.charAt(0) === '/') { return v; }
            return joinPublicUrl(getUploadPublicBase(), v);
        }

        function parseUploadSizeValue(value) {
            if (value === null || typeof value === 'undefined') {
                return null;
            }
            if (typeof value === 'number') {
                return Number.isFinite(value) && value > 0 ? Math.round(value) : null;
            }
            var text = String(value || '').trim();
            if (!text) {
                return null;
            }
            if (/^\d+(?:\.\d+)?$/.test(text)) {
                var numeric = Number(text);
                return Number.isFinite(numeric) && numeric > 0 ? Math.round(numeric) : null;
            }
            var match = text.match(/^(\d+(?:\.\d+)?)\s*(b|kb|k|mb|m|gb|g)$/i);
            if (!match) {
                return null;
            }
            var amount = Number(match[1]);
            if (!Number.isFinite(amount) || amount <= 0) {
                return null;
            }
            var unit = String(match[2] || 'b').toLowerCase();
            var multiplier = 1;
            if (unit === 'kb' || unit === 'k') {
                multiplier = 1024;
            } else if (unit === 'mb' || unit === 'm') {
                multiplier = 1024 * 1024;
            } else if (unit === 'gb' || unit === 'g') {
                multiplier = 1024 * 1024 * 1024;
            }
            return Math.round(amount * multiplier);
        }

        function formatUploadSize(bytes) {
            var size = Number(bytes);
            if (!Number.isFinite(size) || size <= 0) {
                return '0B';
            }
            var units = [
                { suffix: 'GB', value: 1024 * 1024 * 1024 },
                { suffix: 'MB', value: 1024 * 1024 },
                { suffix: 'KB', value: 1024 }
            ];
            for (var i = 0; i < units.length; i += 1) {
                if (size >= units[i].value) {
                    var amount = size / units[i].value;
                    var precision = amount >= 10 ? 0 : 2;
                    return amount.toFixed(precision).replace(/\.?0+$/, '') + units[i].suffix;
                }
            }
            return Math.round(size) + 'B';
        }

        function resolveUploadMaxSize(params, isImage) {
            var limits = clientConfig && clientConfig.upload_limits ? clientConfig.upload_limits : {};
            var typeLimits = isImage ? limits.image : limits.file;
            var candidates = [];
            if (typeLimits && typeof typeLimits === 'object') {
                var effective = parseUploadSizeValue(typeLimits.effective);
                if (effective) {
                    candidates.push({
                        size: effective,
                        source: String(typeLimits.source || '').trim() || 'app'
                    });
                }
            }
            if (params && typeof params === 'object') {
                var override = Object.prototype.hasOwnProperty.call(params, 'max_size') ? params.max_size : params.maxSize;
                var parsedOverride = parseUploadSizeValue(override);
                if (parsedOverride) {
                    candidates.push({
                        size: parsedOverride,
                        source: 'field'
                    });
                }
            }
            if (!candidates.length) {
                return null;
            }
            var selected = candidates[0];
            candidates.forEach(function(candidate) {
                if (candidate.size < selected.size) {
                    selected = candidate;
                }
            });
            return selected;
        }

        function buildUploadTooLargeMessage(isImage, limit) {
            var label = isImage ? 'Image' : 'File';
            var maxSize = limit && typeof limit === 'object' ? limit.size : limit;
            var source = limit && typeof limit === 'object' ? String(limit.source || '') : '';
            var formatted = formatUploadSize(maxSize);
            if (source.indexOf('php_') === 0) {
                var directive = source.slice(4);
                return label + ' exceeds the PHP upload limit of ' + formatted + ' (' + directive + ').';
            }
            if (source === 'field') {
                return label + ' exceeds the field upload limit of ' + formatted + '.';
            }
            return label + ' exceeds the maximum upload size of ' + formatted + '.';
        }

        function normalizeFilePondErrorMessage(error, fallback) {
            var message = '';
            if (typeof error === 'string') {
                message = error;
            } else if (error && typeof error === 'object') {
                if (typeof error.body === 'string') {
                    message = error.body;
                } else if (typeof error.message === 'string') {
                    message = error.message;
                } else if (typeof error.main === 'string') {
                    message = error.main;
                } else if (typeof error.error === 'string') {
                    message = error.error;
                }
            }
            message = String(message || '').replace(/\s+/g, ' ').trim();
            if (message && message !== 'Error during upload') {
                return message;
            }
            return String(fallback || 'Upload failed.').replace(/\s+/g, ' ').trim();
        }

        function hydrateFilePondInitialSizes(pond, initialFiles) {
            if (!pond || typeof fetch !== 'function' || typeof FormData === 'undefined' || !Array.isArray(initialFiles) || !initialFiles.length) {
                return;
            }

            var entries = initialFiles.map(function(entry) {
                var storedName = entry && entry.options && entry.options.metadata ? entry.options.metadata.storedName : '';
                return {
                    storedName: storedName,
                    source: entry && entry.source ? entry.source : ''
                };
            }).filter(function(entry) {
                return !!entry.storedName;
            });

            if (!entries.length) {
                return;
            }

            var uniqueNames = [];
            var seen = Object.create(null);
            var sourceByName = Object.create(null);
            entries.forEach(function(entry) {
                if (!entry.storedName || seen[entry.storedName]) {
                    return;
                }
                seen[entry.storedName] = true;
                uniqueNames.push(entry.storedName);
                sourceByName[entry.storedName] = entry.source || toPublicUrl(entry.storedName);
            });

            if (!uniqueNames.length) {
                return;
            }

            function formatReadableFileSize(bytes) {
                if (!bytes || !Number.isFinite(bytes) || bytes <= 0) {
                    return '0 B';
                }
                var units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
                var exponent = Math.floor(Math.log(bytes) / Math.log(1024));
                if (!Number.isFinite(exponent) || exponent < 0) {
                    exponent = 0;
                }
                exponent = Math.min(exponent, units.length - 1);
                var value = bytes / Math.pow(1024, exponent);
                var precision = (value >= 10 || exponent === 0) ? 0 : 2;
                return value.toFixed(precision) + ' ' + units[exponent];
            }

            function filePondItemMatchesStoredName(item, storedName) {
                if (!item) {
                    return false;
                }
                if (item.getMetadata && typeof item.getMetadata === 'function' && item.getMetadata('storedName') === storedName) {
                    return true;
                }
                return !!(item.source && typeof item.source === 'string' && item.source.indexOf(storedName) !== -1);
            }

            function updateItemsWithLabel(storedName, label, onlyIfMissing, asBadge) {
                var files = typeof pond.getFiles === 'function' ? pond.getFiles() : [];
                if (!files.length) {
                    return false;
                }
                var labelUpdated = false;
                files.forEach(function(item) {
                    if (!filePondItemMatchesStoredName(item, storedName)) {
                        return;
                    }
                    try {
                        if (pond.element && item.id) {
                            var info = null;
                            var itemElement = pond.element.querySelector('#filepond--item-' + item.id);
                            if (itemElement) {
                                info = itemElement.querySelector('.filepond--file-info-sub');
                            }
                            if (!info) {
                                var selector = '[data-filepond-item-id="' + item.id + '"] .filepond--file-info-sub';
                                info = pond.element.querySelector(selector);
                            }
                            if (info) {
                                if (onlyIfMissing) {
                                    var current = String(info.textContent || '').replace(/\s+/g, ' ').trim();
                                    if (current && current !== '0 bytes' && current !== '0 B' && current !== 'Loading size...') {
                                        labelUpdated = true;
                                        return;
                                    }
                                }
                                info.textContent = label;
                                if (asBadge) {
                                    info.classList.add('fastcrud-filepond-size-badge');
                                } else {
                                    info.classList.remove('fastcrud-filepond-size-badge');
                                }
                                labelUpdated = true;
                            }
                        }
                    } catch (e) {}
                });
                return labelUpdated;
            }

            function updateItemsWithSize(storedName, size) {
                var files = typeof pond.getFiles === 'function' ? pond.getFiles() : [];
                if (!files.length) {
                    return false;
                }
                files.forEach(function(item) {
                    if (!filePondItemMatchesStoredName(item, storedName)) {
                        return;
                    }
                    try {
                        if (item.setMetadata && typeof item.setMetadata === 'function') {
                            item.setMetadata('size', size, true);
                            item.setMetadata('filesize', size, true);
                        }
                        item.fileSize = size;
                        if (item.file && typeof item.file === 'object') {
                            Object.defineProperty(item.file, 'size', { value: size, configurable: true });
                        }
                    } catch (e) {}
                });
                return updateItemsWithLabel(storedName, formatReadableFileSize(size), false, true);
            }

            function scheduleLabelUpdates(labelsByName, maxAttempts, delayMs, onlyIfMissing) {
                var attempts = 0;
                var applyLabels = function() {
                    var pending = false;
                    Object.keys(labelsByName).forEach(function(key) {
                        if (!updateItemsWithLabel(key, labelsByName[key], onlyIfMissing)) {
                            pending = true;
                        }
                    });
                    if (pending && attempts < maxAttempts) {
                        attempts += 1;
                        setTimeout(applyLabels, delayMs);
                    }
                };
                applyLabels();
            }

            var loadingLabels = {};
            uniqueNames.forEach(function(key) {
                loadingLabels[key] = 'Loading size...';
            });
            scheduleLabelUpdates(loadingLabels, 20, 250, true);

            function resolveSizeFromPublicUrl(storedName, callback) {
                if (typeof fetch !== 'function') {
                    callback(null, false);
                    return;
                }

                var source = sourceByName[storedName] || toPublicUrl(storedName);
                if (!source) {
                    callback(null, false);
                    return;
                }

                fetch(source, {
                    method: 'HEAD',
                    credentials: 'same-origin'
                }).then(function(response) {
                    if (!response || !response.ok) {
                        callback(null, true);
                        return;
                    }
                    var contentLength = response.headers ? response.headers.get('content-length') : null;
                    var size = contentLength ? Number(contentLength) : NaN;
                    callback(Number.isFinite(size) && size > 0 ? size : null, false);
                }).catch(function() {
                    callback(null, false);
                });
            }

            try {
                var formData = new FormData();
                formData.append('fastcrud_ajax', '1');
                formData.append('action', 'file_metadata_bulk');
                formData.append('names', JSON.stringify(uniqueNames));

                fetch(window.location.pathname, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: formData
                }).then(function(response) {
                    if (!response || typeof response.json !== 'function') {
                        throw new Error('invalid_response');
                    }
                    return response.json();
                }).then(function(payload) {
                    if (!payload || payload.success !== true || typeof payload.sizes !== 'object' || payload.sizes === null) {
                        return;
                    }
                    var attempts = 0;
                    var applySizes = function() {
                        var pending = false;
                        var knownSizes = Object.create(null);
                        Object.keys(payload.sizes).forEach(function(key) {
                            if (!Object.prototype.hasOwnProperty.call(payload.sizes, key)) {
                                return;
                            }
                            var size = Number(payload.sizes[key]);
                            if (!Number.isFinite(size) || size <= 0) {
                                return;
                            }
                            knownSizes[key] = true;
                            if (!updateItemsWithSize(key, size)) {
                                pending = true;
                            }
                        });
                        uniqueNames.forEach(function(key) {
                            if (knownSizes[key]) {
                                return;
                            }
                            resolveSizeFromPublicUrl(key, function(size, missing) {
                                var label = missing ? 'File missing' : 'Size unavailable';
                                if (size !== null) {
                                    updateItemsWithSize(key, size);
                                } else {
                                    updateItemsWithLabel(key, label);
                                }
                            });
                        });
                        if (pending && attempts < 20) {
                            attempts += 1;
                            setTimeout(applySizes, 250);
                        }
                    };
                    applySizes();
                }).catch(function() {});
            } catch (e) {}
        }

        function extractFileName(value) {
            var str = String(value || '').trim();
            if (!str) { return ''; }
            var hashIndex = str.indexOf('#');
            if (hashIndex !== -1) {
                str = str.slice(0, hashIndex);
            }
            var queryIndex = str.indexOf('?');
            if (queryIndex !== -1) {
                str = str.slice(0, queryIndex);
            }
            var parts = str.split('/');
            var segment = parts[parts.length - 1] || '';
            if (!segment) { return ''; }
            var lastSlash = segment.lastIndexOf('/');
            var lastBackslash = segment.lastIndexOf(String.fromCharCode(92));
            var separatorIndex = Math.max(lastSlash, lastBackslash);
            if (separatorIndex !== -1) {
                return segment.slice(separatorIndex + 1) || '';
            }
            return segment;
        }

        function collapseRepeatedSlashes(input) {
            var result = input;
            while (result.indexOf('//') !== -1) {
                result = result.replace('//', '/');
            }
            return result;
        }

        function normalizeStoredImageName(value) {
            if (value === null || typeof value === 'undefined') {
                return '';
            }
            var str = String(value).trim();
            if (!str.length) {
                return '';
            }
            var hashIndex = str.indexOf('#');
            if (hashIndex !== -1) {
                str = str.slice(0, hashIndex);
            }
            var queryIndex = str.indexOf('?');
            if (queryIndex !== -1) {
                str = str.slice(0, queryIndex);
            }
            str = str.split('\\').join('/');
            str = collapseRepeatedSlashes(str);
            while (str.indexOf('./') === 0) {
                str = str.slice(2);
            }
            if (str === '.' || !str.length) {
                return '';
            }
            return str;
        }

        function normalizeUploadSubPath(pathOption) {
            if (!pathOption) {
                return '';
            }
            var candidate = String(pathOption).trim();
            if (!candidate.length) {
                return '';
            }
            if (/^https?:\/\//i.test(candidate)) {
                try {
                    var parsed = new URL(candidate, window.location.origin);
                    candidate = parsed.pathname || '';
                } catch (e) {
                    candidate = '';
                }
            }
            candidate = candidate.split('\\').join('/');
            candidate = collapseRepeatedSlashes(candidate);
            while (candidate.charAt(0) === '/') {
                candidate = candidate.slice(1);
            }
            while (candidate.charAt(candidate.length - 1) === '/') {
                candidate = candidate.slice(0, -1);
            }
            if (!candidate.length) {
                return '';
            }
            var segments = candidate.split('/').filter(function(item) { return item.length > 0; });
            if (!segments.length) {
                return '';
            }
            if (segments[0].toLowerCase() === 'public') {
                segments.shift();
            }
            var basePath = getUploadPublicBase();
            while (basePath.charAt(0) === '/') {
                basePath = basePath.slice(1);
            }
            while (basePath.charAt(basePath.length - 1) === '/') {
                basePath = basePath.slice(0, -1);
            }
            var baseSegments = basePath.split('/').filter(function(item) {
                return item.length > 0;
            });
            if (segments.length && baseSegments.length) {
                var lastBase = baseSegments[baseSegments.length - 1];
                if (lastBase && segments[0].toLowerCase() === lastBase.toLowerCase()) {
                    segments.shift();
                }
            }
            return segments.join('/');
        }

        function parseImageNameList(value) {
            var result = [];
            var push = function(candidate) {
                var normalized = normalizeStoredImageName(candidate);
                if (normalized && result.indexOf(normalized) === -1) {
                    result.push(normalized);
                }
            };

            if (Array.isArray(value)) {
                value.forEach(push);
                return result;
            }

            var text = String(value || '').trim();
            if (!text.length) {
                return result;
            }

            text.split(',').forEach(push);

            return result;
        }

        function imageNamesToString(list) {
            if (!Array.isArray(list) || !list.length) {
                return '';
            }
            var normalized = [];
            list.forEach(function(item) {
                var name = normalizeStoredImageName(item);
                if (name && normalized.indexOf(name) === -1) {
                    normalized.push(name);
                }
            });
            return normalized.join(',');
        }

        function setImageNamesOnInput(input, list) {
            if (!input || !input.length) {
                return;
            }
            input.val(imageNamesToString(Array.isArray(list) ? list : parseImageNameList(list)));
        }

        function addImageNameToInput(input, candidate) {
            if (!input || !input.length) {
                return;
            }
            var name = normalizeStoredImageName(candidate);
            if (!name) {
                return;
            }
            var current = parseImageNameList(input.val());
            if (current.indexOf(name) === -1) {
                current.push(name);
            }
            input.val(imageNamesToString(current));
        }

        function removeImageNameFromInput(input, candidate) {
            if (!input || !input.length) {
                return;
            }
            var name = normalizeStoredImageName(candidate);
            if (!name) {
                return;
            }
            var current = parseImageNameList(input.val());
            var filtered = current.filter(function(entry) {
                return entry !== name;
            });
            if (filtered.length !== current.length) {
                input.val(imageNamesToString(filtered));
            }
        }

        function clearImageNameMap(input) {
            if (!input || !input.length) {
                return;
            }
            input.removeData('fastcrudNameMap');
        }

        function ensureImageNameMap(input) {
            if (!input || !input.length) {
                return {};
            }
            var existing = input.data('fastcrudNameMap');
            if (!existing || typeof existing !== 'object') {
                existing = {};
                input.data('fastcrudNameMap', existing);
            } else {
                input.data('fastcrudNameMap', existing);
            }
            return existing;
        }

        function mapImageNameToKey(input, key, name) {
            if (!input || !input.length || !key) {
                return;
            }
            var map = ensureImageNameMap(input);
            var normalized = normalizeStoredImageName(name);
            if (normalized) {
                map[key] = normalized;
            }
            input.data('fastcrudNameMap', map);
        }

        function removeImageNameForKey(input, key) {
            if (!input || !input.length || !key) {
                return;
            }
            var map = input.data('fastcrudNameMap');
            if (map && typeof map === 'object' && Object.prototype.hasOwnProperty.call(map, key)) {
                delete map[key];
                input.data('fastcrudNameMap', map);
            }
        }

        function findImageNameForKey(input, key) {
            if (!input || !input.length || !key) {
                return '';
            }
            var map = input.data('fastcrudNameMap');
            if (map && typeof map === 'object' && Object.prototype.hasOwnProperty.call(map, key)) {
                return map[key];
            }
            return '';
        }

        function appendStylesheetOnce(href, id) {
            if (!href) { return; }
            var markerId = id || ('fastcrud-style-' + Math.random().toString(36).slice(2));
            if (document.getElementById(markerId)) {
                return;
            }
            var link = document.createElement('link');
            link.id = markerId;
            link.rel = 'stylesheet';
            link.href = href;
            document.head.appendChild(link);
        }

        function withSortableAssets(callback) {
            if (!isRowOrderingActive() || typeof callback !== 'function') {
                return;
            }
            if (typeof window.Sortable !== 'undefined' && typeof window.Sortable.create === 'function') {
                sortableState.loaded = true;
                callback();
                return;
            }
            sortableState.queue.push(callback);
            if (sortableState.loading) {
                return;
            }
            sortableState.loading = true;

            var script = document.createElement('script');
            script.src = sortableState.scriptUrl;
            script.async = true;
            script.onload = function() {
                sortableState.loading = false;
                sortableState.loaded = (typeof window.Sortable !== 'undefined' && typeof window.Sortable.create === 'function');
                var queued = sortableState.queue.slice();
                sortableState.queue.length = 0;
                if (!sortableState.loaded) {
                    if (window.console && console.error) {
                        console.error('FastCrud: SortableJS loaded but did not expose Sortable.create');
                    }
                    return;
                }
                queued.forEach(function(fn) {
                    try { fn(); } catch (error) { if (window.console && console.error) { console.error(error); } }
                });
            };
            script.onerror = function() {
                sortableState.loading = false;
                sortableState.queue.length = 0;
                if (window.console && console.error) {
                    console.error('FastCrud: failed to load SortableJS script');
                }
            };
            document.head.appendChild(script);
        }

        function withFilePondAssets(callback) {
            if (typeof callback !== 'function') {
                return;
            }
            if (typeof window.FilePond !== 'undefined' && typeof window.FilePond.create === 'function' && typeof window.FilePondPluginImagePreview !== 'undefined') {
                filePondState.loaded = true;
                callback();
                return;
            }
            filePondState.queue.push(callback);
            if (filePondState.loading) {
                return;
            }
            filePondState.loading = true;

            // Load styles first
            appendStylesheetOnce(filePondState.coreStyleUrl, 'fastcrud-filepond-core-css');
            appendStylesheetOnce(filePondState.previewStyleUrl, 'fastcrud-filepond-preview-css');
            appendStylesheetOnce(filePondState.posterStyleUrl, 'fastcrud-filepond-poster-css');

            // Ensure poster images are contained (avoid cropping)
            try {
                var containStyleId = 'fastcrud-filepond-contain-css';
                if (!document.getElementById(containStyleId)) {
                    var styleTag = document.createElement('style');
                    styleTag.id = containStyleId;
                    // Keep CSS on a single JS line to avoid syntax errors inside heredoc
                    styleTag.textContent = '.filepond--file-poster img{width:100%;height:100%;object-fit:contain;}';
                    document.head.appendChild(styleTag);
                }
            } catch (e) {}

            // Align FilePond panels/drop areas with the active Bootstrap theme (light/dark)
            try {
                var themeStyleId = 'fastcrud-filepond-theme-css';
                if (!document.getElementById(themeStyleId)) {
                    var themeStyle = document.createElement('style');
                    themeStyle.id = themeStyleId;
                    themeStyle.textContent = ':root{--fastcrud-filepond-panel-bg:var(--bs-body-bg,#fff);--fastcrud-filepond-surface:var(--bs-tertiary-bg,#f8f9fa);--fastcrud-filepond-border-color:var(--bs-border-color,#dee2e6);--fastcrud-filepond-border-hover:var(--bs-primary,#0d6efd);--fastcrud-filepond-label-color:var(--bs-secondary-color,rgba(33,37,41,0.75));--fastcrud-filepond-text-color:var(--bs-body-color,#212529);--fastcrud-filepond-subtle-color:var(--bs-secondary-color,rgba(33,37,41,0.6));--fastcrud-filepond-legend-color:var(--fastcrud-filepond-text-color);--fastcrud-filepond-action-bg:var(--bs-primary-bg-subtle,rgba(13,110,253,0.12));--fastcrud-filepond-action-color:var(--bs-primary,#0d6efd);--fastcrud-filepond-size-bg:rgba(33,37,41,0.82);--fastcrud-filepond-size-color:#fff;--fastcrud-filepond-size-border:rgba(255,255,255,0.35);--fastcrud-filepond-shadow:rgba(15,23,42,0.1);}' +
                        '[data-bs-theme=light]{--fastcrud-filepond-panel-bg:var(--bs-body-bg,#fff);--fastcrud-filepond-surface:var(--bs-tertiary-bg,#f8f9fa);--fastcrud-filepond-shadow:rgba(15,23,42,0.1);--fastcrud-filepond-text-color:var(--bs-body-color,#212529);--fastcrud-filepond-subtle-color:var(--bs-secondary-color,rgba(73,80,87,0.75));--fastcrud-filepond-legend-color:var(--bs-body-color,#212529);--fastcrud-filepond-size-bg:rgba(33,37,41,0.82);--fastcrud-filepond-size-color:#fff;--fastcrud-filepond-size-border:rgba(255,255,255,0.35);}' +
                        '[data-bs-theme=dark]{--fastcrud-filepond-panel-bg:var(--bs-body-bg,#212529);--fastcrud-filepond-surface:var(--bs-tertiary-bg,#2b3035);--fastcrud-filepond-border-color:rgba(255,255,255,0.14);--fastcrud-filepond-border-hover:var(--bs-primary,#6ea8fe);--fastcrud-filepond-label-color:var(--bs-secondary-color,#adb5bd);--fastcrud-filepond-text-color:var(--bs-body-color,#dee2e6);--fastcrud-filepond-subtle-color:rgba(222,226,230,0.7);--fastcrud-filepond-legend-color:var(--bs-body-color,#dee2e6);--fastcrud-filepond-action-bg:rgba(110,168,254,0.16);--fastcrud-filepond-action-color:var(--bs-primary,#6ea8fe);--fastcrud-filepond-size-bg:rgba(33,37,41,0.86);--fastcrud-filepond-size-color:#fff;--fastcrud-filepond-size-border:rgba(255,255,255,0.35);--fastcrud-filepond-shadow:rgba(0,0,0,0.35);}' +
                        '.filepond--root{margin-top:0.25rem;background:var(--fastcrud-filepond-surface)!important;border:1px dashed var(--fastcrud-filepond-border-color)!important;border-radius:0.75rem!important;padding:0.65rem!important;box-shadow:0 0.8rem 1.8rem -1.5rem var(--fastcrud-filepond-shadow);transition:background .2s ease,border-color .2s ease,box-shadow .2s ease;}' +
                        '.filepond--root:hover,.filepond--root[data-hopper-state=drag-over]{border-color:var(--fastcrud-filepond-border-hover)!important;box-shadow:0 1rem 2rem -1.45rem var(--fastcrud-filepond-shadow);}' +
                        '.filepond--root[data-hopper-state=drag-over]{background:var(--fastcrud-filepond-panel-bg)!important;}' +
                        '.filepond--panel-root,.filepond--panel-top,.filepond--panel-center,.filepond--panel-bottom{background-color:transparent!important;border:none!important;box-shadow:none!important;}' +
                        '.filepond--panel-root::before,.filepond--panel-root::after{background:transparent!important;}' +
                        '.filepond--drop-label{min-height:6rem;color:var(--fastcrud-filepond-label-color)!important;font-weight:500;line-height:1.35;}' +
                        '.filepond--drop-label label{padding:0.5rem 0.75rem!important;}' +
                        '.filepond--drop-label span{color:inherit!important;}' +
                        '.filepond--label-action{display:inline-flex!important;align-items:center;justify-content:center;margin-left:0.18rem;padding:0.18rem 0.52rem;border-radius:999px;background:var(--fastcrud-filepond-action-bg);color:var(--fastcrud-filepond-action-color)!important;font-weight:700;text-decoration:none!important;}' +
                        '.filepond legend{color:var(--fastcrud-filepond-legend-color)!important;font-weight:600;}' +
                        '.filepond--file-info{color:var(--fastcrud-filepond-text-color)!important;position:relative!important;z-index:5!important;}' +
                        '.filepond--file-info span{color:inherit!important;}' +
                        '.filepond--file-info-sub{color:var(--fastcrud-filepond-subtle-color)!important;}' +
                        '.fastcrud-filepond--single-image .filepond--file-info,.fastcrud-filepond--multi-image .filepond--file-info{flex:1 1 auto!important;width:calc(100% - 2.75em)!important;max-width:calc(100% - 2.75em)!important;min-width:0!important;margin-right:2.75em!important;}' +
                        '.fastcrud-filepond--single-image .filepond--file-info-main,.fastcrud-filepond--multi-image .filepond--file-info-main{display:block!important;width:100%!important;max-width:100%!important;overflow:hidden!important;text-overflow:ellipsis!important;white-space:nowrap!important;}' +
                        '.fastcrud-filepond--single-image .filepond--file-status,.fastcrud-filepond--multi-image .filepond--file-status{position:absolute!important;right:0.5625em;bottom:0.5625em;max-width:calc(100% - 1.125em);margin:0!important;}' +
                        '.filepond--file-poster-wrapper{z-index:1!important;}' +
                        '.filepond--file .filepond--file-info .filepond--file-info-sub.fastcrud-filepond-size-badge{display:inline-flex!important;align-items:center;max-width:max-content;margin-top:0.2rem;padding:0.12rem 0.42rem;border:1px solid var(--fastcrud-filepond-size-border);border-radius:999px;background:var(--fastcrud-filepond-size-bg)!important;color:var(--fastcrud-filepond-size-color)!important;font-size:0.72rem;font-weight:700;line-height:1.15;letter-spacing:0;white-space:nowrap;text-shadow:none!important;box-shadow:0 0.18rem 0.55rem rgba(15,23,42,0.18);opacity:1!important;visibility:visible!important;}' +
                        '.filepond--item-panel{background-color:var(--fastcrud-filepond-panel-bg)!important;border:1px solid var(--fastcrud-filepond-border-color)!important;border-radius:0.65rem!important;box-shadow:0 0.55rem 1.2rem -1rem var(--fastcrud-filepond-shadow);}' +
                        '.filepond--file{border-radius:0.65rem!important;}' +
                        '.fastcrud-filepond--single-image.filepond--root{max-width:22rem!important;width:100%!important;display:block;}' +
                        '.fastcrud-filepond--single-image .filepond--drop-label{min-height:9rem;}' +
                        '.fastcrud-filepond--single-image .filepond--item{width:100%;}' +
                        '.fastcrud-filepond--multi-image.filepond--root,.fastcrud-filepond--multi-file.filepond--root{width:100%!important;max-width:100%!important;display:block;}' +
                        '.fastcrud-filepond--multi-image .filepond--drop-label{min-height:7.25rem;}' +
                        '.fastcrud-filepond--multi-image .filepond--list{left:0.5em;right:0.5em;}' +
                        '.fastcrud-filepond--multi-image .filepond--item{width:calc(50% - 0.5em);}' +
                        '@media (min-width: 720px){.fastcrud-filepond--multi-image .filepond--item{width:calc(33.333% - 0.5em);}}' +
                        '@media (min-width: 1200px){.fastcrud-filepond--multi-image .filepond--item{width:calc(25% - 0.5em);}}' +
                        '.fastcrud-filepond--file .filepond--drop-label,.fastcrud-filepond--multi-file .filepond--drop-label{min-height:4.75rem;}' +
                        '.fastcrud-filepond--file .filepond--item,.fastcrud-filepond--multi-file .filepond--item{width:100%;}' +
                        '.fastcrud-filepond--file .filepond--file,.fastcrud-filepond--multi-file .filepond--file{min-height:3.1rem;}' +
                        '.fastcrud-filepond--file .filepond--file-info,.fastcrud-filepond--multi-file .filepond--file-info{margin-left:0.75rem;}';
                    document.head.appendChild(themeStyle);
                }
            } catch (e) {}

            // Load FilePond core JS, then plugin JS
            var coreScript = document.createElement('script');
            coreScript.src = filePondState.coreScriptUrl;
            coreScript.referrerPolicy = 'no-referrer';
            coreScript.onload = function() {
                var previewScript = document.createElement('script');
                previewScript.src = filePondState.previewScriptUrl;
                previewScript.referrerPolicy = 'no-referrer';
                previewScript.onload = function() {
                    var posterScript = document.createElement('script');
                    posterScript.src = filePondState.posterScriptUrl;
                    posterScript.referrerPolicy = 'no-referrer';
                    posterScript.onload = function() {
                        try {
                            if (window.FilePond && typeof window.FilePond.registerPlugin === 'function') {
                                if (window.FilePondPluginImagePreview) {
                                    window.FilePond.registerPlugin(window.FilePondPluginImagePreview);
                                }
                                if (window.FilePondPluginFilePoster) {
                                    window.FilePond.registerPlugin(window.FilePondPluginFilePoster);
                                }
                            }
                        } catch (e) {}
                        filePondState.loaded = true;
                        filePondState.loading = false;
                        var queued = filePondState.queue.slice();
                        filePondState.queue.length = 0;
                        queued.forEach(function(fn) {
                            try { fn(); } catch (error) { if (window.console && console.error) console.error(error); }
                        });
                    };
                    posterScript.onerror = function() {
                        filePondState.loading = false;
                        filePondState.queue.length = 0;
                        if (window.console && console.error) console.error('FastCrud: failed to load FilePond file poster script');
                    };
                    document.head.appendChild(posterScript);
                };
                previewScript.onerror = function() {
                    filePondState.loading = false;
                    filePondState.queue.length = 0;
                    if (window.console && console.error) console.error('FastCrud: failed to load FilePond image preview script');
                };
                document.head.appendChild(previewScript);
            };
            coreScript.onerror = function() {
                filePondState.loading = false;
                filePondState.queue.length = 0;
                if (window.console && console.error) console.error('FastCrud: failed to load FilePond core script');
            };
            document.head.appendChild(coreScript);
        }

        function destroyFilePonds(container) {
            if (!container || !container.length) {
                return;
            }
            if (typeof window.FilePond === 'undefined' || typeof window.FilePond.find !== 'function') {
                return;
            }
            try {
                var inputs = container.find('input.fastcrud-filepond').toArray();
                var ponds = window.FilePond.find(inputs);
                (ponds || []).forEach(function(pond) {
                    try { pond.destroy(); } catch (e) {}
                });
            } catch (e) {}
        }

        var select2State = window.FastCrudSelect2 || {};
        if (!select2State.scriptUrl) {
            select2State.scriptUrl = 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js';
        }
        if (!select2State.styleUrl) {
            select2State.styleUrl = 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css';
        }
        if (!Array.isArray(select2State.queue)) {
            select2State.queue = [];
        }
        if (typeof select2State.loaded !== 'boolean') {
            select2State.loaded = (typeof $.fn !== 'undefined' && typeof $.fn.select2 === 'function');
        } else if (select2State.loaded && (typeof $.fn === 'undefined' || typeof $.fn.select2 !== 'function')) {
            select2State.loaded = false;
        }
        if (typeof select2State.loading !== 'boolean') {
            select2State.loading = false;
        }
        window.FastCrudSelect2 = select2State;

        function withSelect2Assets(callback) {
            if (!select2Enabled || typeof callback !== 'function') {
                return;
            }
            if (typeof $.fn !== 'undefined' && typeof $.fn.select2 === 'function') {
                callback();
                return;
            }
            select2State.queue.push(callback);
            if (select2State.loading) {
                return;
            }
            select2State.loading = true;
            appendStylesheetOnce(select2State.styleUrl, 'fastcrud-select2-css');
            try {
                var select2ThemeStyleId = 'fastcrud-select2-theme-css';
                if (!document.getElementById(select2ThemeStyleId)) {
                    var select2ThemeStyle = document.createElement('style');
                    select2ThemeStyle.id = select2ThemeStyleId;
                    var select2ThemeCss = ":root, [data-bs-theme=light]{\n  --fastcrud-select2-bg:var(--bs-body-bg,#fff);\n  --fastcrud-select2-border:var(--bs-border-color,#ced4da);\n  --fastcrud-select2-text:var(--bs-body-color,#212529);\n  --fastcrud-select2-placeholder:var(--bs-secondary-color,rgba(108,117,125,0.75));\n  --fastcrud-select2-dropdown-bg:var(--bs-tertiary-bg,var(--bs-body-bg,#fff));\n  --fastcrud-select2-dropdown-text:var(--bs-body-color,#212529);\n  --fastcrud-select2-highlight-bg:var(--bs-primary,#0d6efd);\n  --fastcrud-select2-highlight-text:var(--bs-primary-contrast,#fff);\n  --fastcrud-select2-selected-bg:rgba(var(--bs-primary-rgb,13,110,253),0.12);\n  --fastcrud-select2-selected-text:var(--fastcrud-select2-highlight-bg);\n  --fastcrud-select2-chip-bg:var(--bs-tertiary-bg,#f8f9fa);\n  --fastcrud-select2-chip-text:var(--bs-body-color,#212529);\n  --fastcrud-select2-chip-border:var(--fastcrud-select2-border);\n  --fastcrud-select2-disabled-bg:var(--bs-secondary-bg,#e9ecef);\n}\n[data-bs-theme=dark]{\n  --fastcrud-select2-bg:var(--bs-body-bg,#212529);\n  --fastcrud-select2-border:var(--bs-border-color,#495057);\n  --fastcrud-select2-text:var(--bs-body-color,#f8f9fa);\n  --fastcrud-select2-placeholder:var(--bs-secondary-color,#adb5bd);\n  --fastcrud-select2-dropdown-bg:var(--bs-tertiary-bg,var(--bs-body-bg,#2b3035));\n  --fastcrud-select2-dropdown-text:var(--bs-body-color,#f8f9fa);\n  --fastcrud-select2-highlight-bg:var(--bs-primary,#4dabf7);\n  --fastcrud-select2-highlight-text:var(--bs-primary-contrast,#fff);\n  --fastcrud-select2-selected-bg:rgba(var(--bs-primary-rgb,13,110,253),0.35);\n  --fastcrud-select2-selected-text:var(--fastcrud-select2-highlight-text);\n  --fastcrud-select2-chip-bg:rgba(255,255,255,0.08);\n  --fastcrud-select2-chip-text:var(--bs-body-color,#f8f9fa);\n  --fastcrud-select2-chip-border:rgba(255,255,255,0.15);\n  --fastcrud-select2-disabled-bg:rgba(255,255,255,0.06);\n}\n.select2-container--default .select2-selection--single,\n.select2-container--default .select2-selection--multiple{\n  background-color:var(--fastcrud-select2-bg);\n  border:1px solid var(--fastcrud-select2-border);\n  color:var(--fastcrud-select2-text);\n  border-radius:var(--bs-border-radius,0.375rem);\n  transition:border-color .15s ease-in-out, box-shadow .15s ease-in-out;\n}\n.select2-container .select2-selection--single{\n  min-height:calc(2.5rem + 2px);\n  display:flex;\n  align-items:center;\n  padding:0.375rem 2rem 0.375rem 0.75rem;\n}\n.select2-container--default .select2-selection--single .select2-selection__rendered{\n  color:var(--fastcrud-select2-text);\n  line-height:1.5;\n  padding:0;\n}\n.select2-container--default .select2-selection--single .select2-selection__placeholder{\n  color:var(--fastcrud-select2-placeholder);\n}\n.select2-container--default .select2-selection--single .select2-selection__arrow{\n  height:100%;\n  right:0.75rem;\n}\n.select2-container--default .select2-selection--single .select2-selection__arrow b{\n  border-color:var(--fastcrud-select2-placeholder) transparent transparent transparent;\n}\n.select2-container--default .select2-selection--multiple{\n  min-height:calc(2.5rem + 2px);\n  padding:0.25rem 0.5rem;\n}\n.select2-container--default .select2-selection--multiple .select2-selection__rendered{\n  display:flex;\n  flex-wrap:wrap;\n  gap:0.35rem;\n  margin:0;\n}\n.select2-container--default .select2-selection--multiple .select2-selection__choice{\n  background-color:var(--fastcrud-select2-chip-bg);\n  border:1px solid var(--fastcrud-select2-chip-border);\n  color:var(--fastcrud-select2-chip-text);\n  border-radius:var(--bs-border-radius-sm,0.25rem);\n  padding:0.1rem 0.5rem;\n}\n.select2-container--default .select2-selection--multiple .select2-selection__choice__remove{\n  color:var(--fastcrud-select2-chip-text);\n  margin-right:0.35rem;\n}\n.select2-container--default .select2-selection--multiple .select2-selection__choice__remove:hover{\n  color:var(--fastcrud-select2-highlight-bg);\n}\n.select2-container--default.select2-container--disabled .select2-selection--single,\n.select2-container--default.select2-container--disabled .select2-selection--multiple{\n  background-color:var(--fastcrud-select2-disabled-bg);\n  opacity:0.75;\n}\n.select2-container--default .select2-dropdown{\n  background-color:var(--fastcrud-select2-dropdown-bg);\n  border:1px solid var(--fastcrud-select2-border);\n  color:var(--fastcrud-select2-dropdown-text);\n  border-radius:var(--bs-border-radius,0.375rem);\n  box-shadow:0 0.5rem 1rem rgba(15,23,42,0.15);\n}\n.select2-container--default .select2-results__option{\n  color:var(--fastcrud-select2-dropdown-text);\n}\n.select2-container--default .select2-results__option--highlighted.select2-results__option--selectable{\n  background-color:var(--fastcrud-select2-highlight-bg);\n  color:var(--fastcrud-select2-highlight-text);\n}\n.select2-container--default .select2-results__option[aria-selected=true]:not(.select2-results__option--highlighted),\n.select2-container--default .select2-results__option--selected:not(.select2-results__option--highlighted){\n  background-color:var(--fastcrud-select2-selected-bg);\n  color:var(--fastcrud-select2-selected-text);\n}\n.select2-search--dropdown .select2-search__field{\n  background-color:var(--fastcrud-select2-bg);\n  color:var(--fastcrud-select2-text);\n  border:1px solid var(--fastcrud-select2-border);\n  border-radius:var(--bs-border-radius,0.375rem);\n}\n.select2-search--dropdown .select2-search__field::placeholder{\n  color:var(--fastcrud-select2-placeholder);\n  opacity:0.75;\n}\n.select2-container--default.select2-container--focus .select2-selection--single,\n.select2-container--default.select2-container--focus .select2-selection--multiple,\nselect:focus + .select2-container--default .select2-selection--single,\nselect:focus + .select2-container--default .select2-selection--multiple{\n  border-color:var(--bs-primary,#0d6efd);\n  box-shadow:0 0 0 0.25rem rgba(var(--bs-primary-rgb,13,110,253),0.25);\n}\nselect.is-invalid + .select2-container--default .select2-selection--single,\nselect.is-invalid + .select2-container--default .select2-selection--multiple,\nselect.was-validated:invalid + .select2-container--default .select2-selection--single,\nselect.was-validated:invalid + .select2-container--default .select2-selection--multiple{\n  border-color:var(--bs-form-invalid-border-color,#dc3545);\n  box-shadow:0 0 0 0.25rem rgba(var(--bs-danger-rgb,220,53,69),0.25);\n}\nselect.is-valid + .select2-container--default .select2-selection--single,\nselect.is-valid + .select2-container--default .select2-selection--multiple,\nselect.was-validated:valid + .select2-container--default .select2-selection--single,\nselect.was-validated:valid + .select2-container--default .select2-selection--multiple{\n  border-color:var(--bs-form-valid-border-color,#198754);\n  box-shadow:0 0 0 0.25rem rgba(var(--bs-success-rgb,25,135,84),0.25);\n}\nselect.form-select-sm + .select2-container .select2-selection--single{\n  min-height:calc(2.25rem + 2px);\n  padding:0.25rem 1.75rem 0.25rem 0.5rem;\n  font-size:0.875rem;\n}\nselect.form-select-sm + .select2-container .select2-selection--multiple{\n  min-height:calc(2.25rem + 2px);\n  padding:0.2rem 0.4rem;\n  font-size:0.875rem;\n}\nselect.form-select-lg + .select2-container .select2-selection--single{\n  min-height:calc(3rem + 2px);\n  padding:0.5rem 2.5rem 0.5rem 1rem;\n  font-size:1.25rem;\n}\nselect.form-select-lg + .select2-container .select2-selection--multiple{\n  min-height:calc(3rem + 2px);\n  padding:0.4rem 0.75rem;\n  font-size:1.25rem;\n}\n.select2-dropdown .select2-results__options::-webkit-scrollbar{width:0.5rem;}\n.select2-dropdown .select2-results__options::-webkit-scrollbar-thumb{background-color:rgba(var(--bs-primary-rgb,13,110,253),0.35);border-radius:1rem;}\n.select2-dropdown .select2-results__options::-webkit-scrollbar-track{background-color:rgba(0,0,0,0.05);}\n[data-bs-theme=dark] .select2-dropdown .select2-results__options::-webkit-scrollbar-thumb{background-color:rgba(255,255,255,0.25);}\n[data-bs-theme=dark] .select2-dropdown .select2-results__options::-webkit-scrollbar-track{background-color:rgba(255,255,255,0.08);}";
                    select2ThemeStyle.textContent = select2ThemeCss;
                    document.head.appendChild(select2ThemeStyle);
                }
            } catch (e) {}
            var script = document.createElement('script');
            script.src = select2State.scriptUrl;
            script.async = true;
            script.onload = function() {
                select2State.loading = false;
                select2State.loaded = true;
                var queued = select2State.queue.slice();
                select2State.queue.length = 0;
                queued.forEach(function(fn) {
                    try { fn(); } catch (error) { if (window.console && console.error) { console.error(error); } }
                });
            };
            script.onerror = function() {
                select2State.loading = false;
                select2State.queue.length = 0;
                if (window.console && console.error) {
                    console.error('FastCrud: failed to load Select2 script');
                }
            };
            document.head.appendChild(script);
        }

        function resolveSelect2DropdownParent(select) {
            if (select && select.hasClass('fastcrud-inline-input')) {
                return $('body');
            }

            var parent = select.closest('.offcanvas.show, .modal.show');
            if (parent.length) {
                return parent;
            }
            parent = select.parent();
            if (parent.length) {
                return parent;
            }
            return $('body');
        }

        var pendingWidgets = new Map();
        var widgetObserver = typeof IntersectionObserver === 'function' ? new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (!entry.isIntersecting) { return; }
                var callback = pendingWidgets.get(entry.target);
                pendingWidgets.delete(entry.target);
                widgetObserver.unobserve(entry.target);
                if (!disposed && entry.target.isConnected && callback) { callback(); }
            });
        }) : null;

        function whenWidgetVisible(element, callback) {
            if (!element || disposed) { return; }
            if (!widgetObserver) { callback(); return; }
            pendingWidgets.set(element, callback);
            widgetObserver.observe(element);
        }

        function cancelPendingWidgets(container) {
            var root = container && container.get(0);
            pendingWidgets.forEach(function(callback, element) {
                if (!root || root === element || root.contains(element)) {
                    if (widgetObserver) { widgetObserver.unobserve(element); }
                    pendingWidgets.delete(element);
                }
            });
        }

        function withVisibleFilePondAssets(group, callback) {
            whenWidgetVisible(group.get(0), function() {
                withFilePondAssets(function() {
                    if (!disposed && group.get(0).isConnected) { callback(); }
                });
            });
        }

        function initializeSelect2(container, visible) {
            if (!select2Enabled) {
                return;
            }
            if (!container || !container.length) {
                return;
            }
            var selectors = 'select[data-fastcrud-type="select"], select[data-fastcrud-type="multiselect"], select.fastcrud-inline-input';
            var elements = container.is('select') ? container.filter(selectors) : container.find(selectors);
            if (!elements.length) {
                return;
            }
            if (!visible) {
                elements.each(function() {
                    var element = this;
                    whenWidgetVisible(element, function() { initializeSelect2($(element), true); });
                });
                return;
            }
            withSelect2Assets(function() {
                if (typeof $.fn === 'undefined' || typeof $.fn.select2 !== 'function') {
                    return;
                }
                elements.each(function() {
                    if (disposed || !this.isConnected) { return; }
                    var select = $(this);
                    if (select.data('select2')) {
                        return;
                    }
                    var isMultiple = select.prop('multiple');
                    var placeholder = select.attr('data-placeholder') || select.attr('placeholder') || null;
                    if (!placeholder && !isMultiple) {
                        var blankOption = select.find('option').filter(function() {
                            var value = $(this).attr('value');
                            if (typeof value === 'undefined' || value === null) {
                                return true;
                            }
                            return String(value).trim() === '';
                        }).first();
                        if (blankOption.length) {
                            placeholder = blankOption.text();
                        }
                    }
                    var options = {
                        width: '100%',
                        dropdownParent: resolveSelect2DropdownParent(select)
                    };
                    if (placeholder) {
                        options.placeholder = placeholder;
                        options.allowClear = !isMultiple;
                    } else if (!isMultiple && select.find('option[value=""]').length) {
                        options.allowClear = true;
                    }
                    try {
                        select.select2(options);
                    } catch (error) {
                        if (window.console && console.error) {
                            console.error('FastCrud: failed to initialize Select2', error);
                        }
                    }
                });
            });
        }

        function destroySelect2(container) {
            if (!container || !container.length) {
                return;
            }
            if (typeof $.fn === 'undefined' || typeof $.fn.select2 !== 'function') {
                return;
            }
            var selectors = 'select[data-fastcrud-type="select"], select[data-fastcrud-type="multiselect"], select.fastcrud-inline-input';
            var elements = container.is('select') ? container.filter(selectors) : container.find(selectors);
            elements.each(function() {
                var select = $(this);
                if (select.data('select2')) {
                    try { select.select2('destroy'); } catch (e) {}
                }
            });
        }

        var richEditorState = window.FastCrudRichEditor || {};
        if (!richEditorState.scriptUrl) {
            richEditorState.scriptUrl = 'https://mzgs.net/tinymce5/tinymce.min.js';
        }
        if (!richEditorState.baseConfig) {
            richEditorState.baseConfig = {
                menubar: false,
                height: 500,
                branding: false,
                paste_data_images: true,
                automatic_uploads: true,
                powerpaste_word_import: 'merge',
                powerpaste_html_import: 'merge',
                powerpaste_allow_local_images: true,
                images_upload_url: window.location.pathname,
                images_upload_base_path: richEditorConfig.upload_path || '/public/uploads',
                images_upload_credentials: true,
                valid_elements: '*[*]',
                images_file_types: 'jpeg,jpg,jpe,jfi,jif,jfif,png,gif,bmp,webp,svg',
                file_picker_types: 'file image media',
                plugins: 'advlist textcolor anchor autolink fullscreen image lists link media code preview searchreplace table visualblocks wordcount pagebreak powerpaste',
                toolbar: 'undo redo | formatselect bold italic removeformat | forecolor backcolor | alignleft aligncenter alignright alignjustify | table bullist numlist pagebreak hr| link image media insertfile | fullscreen code preview',
                spellchecker_dialog: true,
                license_key: 'gpl'
            };
        }
        if (richEditorConfig.upload_path) {
            richEditorState.baseConfig.images_upload_base_path = richEditorConfig.upload_path;
        }
        if (richEditorConfig.upload_url) {
            richEditorState.baseConfig.images_upload_url = richEditorConfig.upload_url;
        }
        richEditorState.baseConfig.images_upload_credentials = true;
        if (!Array.isArray(richEditorState.queue)) {
            richEditorState.queue = [];
        }
        if (typeof richEditorState.loaded === 'undefined') {
            richEditorState.loaded = typeof window.tinymce !== 'undefined';
        }
        if (typeof richEditorState.loading === 'undefined') {
            richEditorState.loading = false;
        }
        window.FastCrudRichEditor = richEditorState;

        var sidePanelHeightTimer = null;
        var sidePanelResizeNamespace = '.fastcrudSidePanel' + String(tableId || '').replace(/[^A-Za-z0-9]/g, '');

        function syncSidePanelHeight() {
            if (formDisplayMode !== 'side' || formOnlyMode || !container.length || !editOffcanvasElement.length) {
                return;
            }

            var panel = container.find('.fastcrud-side-panel.show').first();
            if (!container.hasClass('fastcrud-side-open') || !panel.length) {
                container.find('.fastcrud-side-panel').css({ height: '', marginTop: '' });
                return;
            }

            var tableColumn = container.find('.fastcrud-side-table').first();
            if (!tableColumn.length) {
                return;
            }

            var tableViewport = tableColumn.find('.fastcrud-table-container').first();
            if (!tableViewport.length) {
                tableViewport = tableColumn.find('.table-responsive').first();
            }

            var targetHeight = Math.ceil((tableViewport.length ? tableViewport.outerHeight(true) : 0) || 0);
            var topOffset = 0;
            if (tableViewport.length && tableColumn.get(0) && tableViewport.get(0)) {
                var columnRect = tableColumn.get(0).getBoundingClientRect();
                var viewportRect = tableViewport.get(0).getBoundingClientRect();
                topOffset = Math.max(0, Math.round(viewportRect.top - columnRect.top));
            }

            if (targetHeight > 0) {
                container.find('.fastcrud-side-panel').not(panel).css({ height: '', marginTop: '' });
                panel.css({
                    height: targetHeight + 'px',
                    marginTop: topOffset > 0 ? topOffset + 'px' : ''
                });
            }
        }

        function scheduleSidePanelHeightSync() {
            if (formDisplayMode !== 'side' || formOnlyMode) {
                return;
            }

            if (sidePanelHeightTimer !== null) {
                window.clearTimeout(sidePanelHeightTimer);
            }

            sidePanelHeightTimer = window.setTimeout(function() {
                sidePanelHeightTimer = null;
                syncSidePanelHeight();
            }, 0);
        }

        if (formDisplayMode === 'side' && !formOnlyMode && sidePanelResizeNamespace !== '.fastcrudSidePanel') {
            $(window).off('resize' + sidePanelResizeNamespace).on('resize' + sidePanelResizeNamespace, scheduleSidePanelHeightSync);
        }

        function createInlinePanelController(element, callbacks) {
            if (!element) {
                return null;
            }

            var elementRef = $(element);
            var hooks = callbacks && typeof callbacks === 'object' ? callbacks : {};
            return {
                show: function() {
                    elementRef.addClass('fastcrud-inline-visible show');
                    if (hooks.onShow && typeof hooks.onShow === 'function') {
                        hooks.onShow();
                    }
                },
                hide: function() {
                    elementRef.removeClass('fastcrud-inline-visible show');
                    if (hooks.onHide && typeof hooks.onHide === 'function') {
                        hooks.onHide();
                    }
                }
            };
        }

        function moveOffcanvasToBody(offcanvasElement) {
            if (formOnlyMode) {
                return;
            }

            if (!offcanvasElement || !offcanvasElement.length) {
                return;
            }

            if (offcanvasElement.attr('data-fastcrud-side-panel') === '1' || offcanvasElement.attr('data-fastcrud-inline') === '1') {
                return;
            }

            var node = offcanvasElement.get(0);
            if (!node || node.parentNode === document.body || !document.body) {
                return;
            }

            var resolvedTheme = (function() {
                if (container && typeof container.attr === 'function') {
                    var directTheme = container.attr('data-bs-theme');
                    if (typeof directTheme === 'string' && directTheme.length) {
                        return directTheme;
                    }

                    var themedAncestor = container.closest('[data-bs-theme]');
                    if (themedAncestor.length) {
                        var ancestorTheme = themedAncestor.attr('data-bs-theme');
                        if (typeof ancestorTheme === 'string' && ancestorTheme.length) {
                            return ancestorTheme;
                        }
                    }
                }

                var bodyTheme = $('body').attr('data-bs-theme');
                if (typeof bodyTheme === 'string' && bodyTheme.length) {
                    return bodyTheme;
                }

                var htmlTheme = $('html').attr('data-bs-theme');
                if (typeof htmlTheme === 'string' && htmlTheme.length) {
                    return htmlTheme;
                }

                return null;
            }());

            if (resolvedTheme && !offcanvasElement.attr('data-bs-theme')) {
                offcanvasElement.attr('data-bs-theme', resolvedTheme);
            }

            offcanvasElement.attr('data-fastcrud-owner', tableId);
            document.body.appendChild(node);
        }

        function getEditOffcanvasInstance() {
            if (editOffcanvasInstance) {
                return editOffcanvasInstance;
            }

            var element = editOffcanvasElement.get(0);
            if (!element) {
                return null;
            }

            if (formOnlyMode || formDisplayMode === 'side' || formDisplayMode === 'inline') {
                editOffcanvasInstance = createInlinePanelController(element, {
                    onShow: function() {
                        if (formDisplayMode === 'side') {
                            container.addClass('fastcrud-side-open');
                            scheduleSidePanelHeightSync();
                        } else if (formDisplayMode === 'inline') {
                            container.addClass('fastcrud-inline-open');
                        }
                    },
                    onHide: function() {
                        clearRowHighlight();
                        if (formDisplayMode === 'side') {
                            container.removeClass('fastcrud-side-open');
                            editOffcanvasElement.css({ height: '', marginTop: '' });
                        } else if (formDisplayMode === 'inline') {
                            container.removeClass('fastcrud-inline-open');
                        }
                        if (typeof cleanupEditPanelWidgets === 'function') {
                            cleanupEditPanelWidgets();
                        } else {
                            cancelPendingWidgets(editFieldsContainer);
                destroyRichEditors(editFieldsContainer);
                            destroySelect2(editFieldsContainer);
                            destroyFilePonds(editFieldsContainer);
                        }
                    }
                });
                return editOffcanvasInstance;
            }

            if (formDisplayMode === 'modal' && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                editOffcanvasInstance = bootstrap.Modal.getOrCreateInstance(element);
                return editOffcanvasInstance;
            }

            if (typeof bootstrap !== 'undefined' && bootstrap.Offcanvas) {
                editOffcanvasInstance = bootstrap.Offcanvas.getOrCreateInstance(element);
                return editOffcanvasInstance;
            }

            return null;
        }

        function getViewOffcanvasInstance() {
            if (viewOffcanvasInstance) {
                return viewOffcanvasInstance;
            }

            var element = viewOffcanvasElement.get(0);
            if (!element) {
                return null;
            }

            if (formOnlyMode || formDisplayMode === 'side' || formDisplayMode === 'inline') {
                viewOffcanvasInstance = createInlinePanelController(element, {
                    onShow: function() {
                        if (formDisplayMode === 'side') {
                            container.addClass('fastcrud-side-open');
                            scheduleSidePanelHeightSync();
                        } else if (formDisplayMode === 'inline') {
                            container.addClass('fastcrud-inline-open');
                        }
                    },
                    onHide: function() {
                        clearRowHighlight();
                        if (formDisplayMode === 'side') {
                            container.removeClass('fastcrud-side-open');
                            viewOffcanvasElement.css({ height: '', marginTop: '' });
                        } else if (formDisplayMode === 'inline') {
                            container.removeClass('fastcrud-inline-open');
                        }
                    }
                });
                return viewOffcanvasInstance;
            }

            if (typeof bootstrap !== 'undefined' && bootstrap.Offcanvas) {
                viewOffcanvasInstance = bootstrap.Offcanvas.getOrCreateInstance(element);
                return viewOffcanvasInstance;
            }

            return null;
        }

        function selectionKey(pkCol, pkVal) {
            if (!pkCol) {
                return '';
            }

            if (typeof pkVal === 'undefined' || pkVal === null) {
                return '';
            }

            return String(pkCol) + '::' + String(pkVal);
        }

        function setSelection(pkCol, pkVal, selected) {
            var key = selectionKey(pkCol, pkVal);
            if (!key) {
                return;
            }

            if (selected) {
                selectedRows[key] = { column: pkCol, value: pkVal };
            } else if (Object.prototype.hasOwnProperty.call(selectedRows, key)) {
                delete selectedRows[key];
            }
        }

        function isSelected(pkCol, pkVal) {
            return Object.prototype.hasOwnProperty.call(selectedRows, selectionKey(pkCol, pkVal));
        }

        function getSelectedCount() {
            return Object.keys(selectedRows).length;
        }

        function clearSelection() {
            selectedRows = {};
            table.find('tbody .fastcrud-select-row').each(function() {
                $(this).prop('checked', false);
            });
            if (selectAllCheckbox && selectAllCheckbox.length) {
                selectAllCheckbox.prop('checked', false).prop('indeterminate', false);
            }
            updateBatchDeleteButtonState();
        }

        function updateBatchDeleteButtonState() {
            if (formOnlyMode) {
                batchDeleteButton = null;
                return;
            }

            if (batchDeleteButton && batchDeleteButton.length) {
                var selectedCount = getSelectedCount();
                var enabled = allowBatchDeleteButton && selectedCount > 0;
                batchDeleteButton.prop('disabled', !enabled);
                var shouldHideButton = !allowBatchDeleteButton || selectedCount === 0;
                batchDeleteButton.toggleClass('d-none', shouldHideButton);
            }

            if (selectAllCheckbox && selectAllCheckbox.length) {
                var allowSelection = batchDeleteEnabled;
                selectAllCheckbox.prop('disabled', !allowSelection);
                if (!allowSelection) {
                    selectAllCheckbox.prop('checked', false).prop('indeterminate', false);
                }
            }

            updateBulkActionState();
        }

        function updateBulkActionState() {
            if (formOnlyMode) {
                return;
            }

            var wrapper = metaContainer.find('.fastcrud-bulk-actions');
            if (!wrapper.length) {
                return;
            }

            var select = wrapper.find('.fastcrud-bulk-action-select');
            var applyBtn = wrapper.find('.fastcrud-bulk-apply-btn');
            if (!applyBtn.length) {
                return;
            }

            var hasSelection = getSelectedCount() > 0;
            var selectedAction = select.length ? select.val() : '';
            applyBtn.prop('disabled', !(hasSelection && selectedAction));
        }

        function refreshSelectAllState() {
            if (!selectAllCheckbox || !selectAllCheckbox.length) {
                return;
            }

            if (!batchDeleteEnabled || !deleteEnabled) {
                selectAllCheckbox.prop('checked', false).prop('indeterminate', false);
                return;
            }

            var enabledCheckboxes = table.find('tbody .fastcrud-select-row').filter(':not(:disabled)');
            if (!enabledCheckboxes.length) {
                selectAllCheckbox.prop('checked', false).prop('indeterminate', false);
                return;
            }

            var checkedCount = enabledCheckboxes.filter(':checked').length;
            selectAllCheckbox.prop('checked', checkedCount === enabledCheckboxes.length);
            selectAllCheckbox.prop('indeterminate', checkedCount > 0 && checkedCount < enabledCheckboxes.length);
        }

        function toggleSelectAll(shouldSelect) {
            if (!batchDeleteEnabled) {
                return;
            }

            var checkboxes = table.find('tbody .fastcrud-select-row').filter(':not(:disabled)');
            checkboxes.each(function() {
                var checkbox = $(this);
                var pkCol = checkbox.attr('data-fastcrud-pk');
                var pkVal = checkbox.attr('data-fastcrud-pk-value');
                if (!pkCol || typeof pkVal === 'undefined') {
                    return;
                }

                checkbox.prop('checked', shouldSelect);
                setSelection(pkCol, pkVal, shouldSelect);
            });

            refreshSelectAllState();
            updateBatchDeleteButtonState();
        }

        function applyMeta(meta) {
            if (!meta || typeof meta !== 'object') {
                return;
            }

            metaConfig = meta;

            if (typeof meta.form_display_mode === 'string' && meta.form_display_mode.length) {
                clientConfig.form_display_mode = meta.form_display_mode;
            }

            if (Object.prototype.hasOwnProperty.call(metaConfig, 'numbers_enabled')) {
                numbersEnabled = !!metaConfig.numbers_enabled;
            } else if (clientConfig && Object.prototype.hasOwnProperty.call(clientConfig, 'numbers_enabled')) {
                numbersEnabled = !!clientConfig.numbers_enabled;
            }
            clientConfig.numbers_enabled = numbersEnabled;

            if (Object.prototype.hasOwnProperty.call(metaConfig, 'compact_pagination')) {
                compactPagination = !!metaConfig.compact_pagination;
            } else if (clientConfig && Object.prototype.hasOwnProperty.call(clientConfig, 'compact_pagination')) {
                compactPagination = !!clientConfig.compact_pagination;
            }
            clientConfig.compact_pagination = compactPagination;

            if (meta.form && typeof meta.form === 'object' && Object.prototype.hasOwnProperty.call(meta.form, 'show_primary_key_field')) {
                showPrimaryKeyField = !!meta.form.show_primary_key_field;
            } else if (clientConfig && clientConfig.form && Object.prototype.hasOwnProperty.call(clientConfig.form, 'show_primary_key_field')) {
                showPrimaryKeyField = !!clientConfig.form.show_primary_key_field;
            }

            if (formOnlyMode) {
                if (Array.isArray(meta.columns) && meta.columns.length) {
                    columnsCache = meta.columns.slice();
                }

                if (typeof meta.primary_key === 'string' && meta.primary_key.length) {
                    primaryKeyColumn = meta.primary_key;
                }

                columnLabels = meta.labels && typeof meta.labels === 'object' ? meta.labels : {};
                columnClasses = meta.column_classes && typeof meta.column_classes === 'object' ? meta.column_classes : {};
                columnWidths = meta.column_widths && typeof meta.column_widths === 'object' ? meta.column_widths : {};

                if (meta.form && typeof meta.form === 'object') {
                    var templates = meta.form.templates && typeof meta.form.templates === 'object'
                        ? deepClone(meta.form.templates)
                        : {};
                    formConfig = {
                        layouts: meta.form.layouts && typeof meta.form.layouts === 'object' ? meta.form.layouts : {},
                        default_tabs: meta.form.default_tabs && typeof meta.form.default_tabs === 'object' ? meta.form.default_tabs : {},
                        behaviours: meta.form.behaviours && typeof meta.form.behaviours === 'object' ? meta.form.behaviours : {},
                        labels: meta.form.labels && typeof meta.form.labels === 'object' ? meta.form.labels : {},
                        all_columns: Array.isArray(meta.form.all_columns) ? meta.form.all_columns.slice() : [],
                        sections: meta.form.sections && typeof meta.form.sections === 'object' ? meta.form.sections : {},
                        templates: templates,
                        show_primary_key_field: showPrimaryKeyField
                    };
                    formTemplates = templates;
                    requiresCustomFieldHtml = Object.keys(templates).length > 0;
                    clientConfig.form = $.extend(true, {}, meta.form);
                    clientConfig.form.show_primary_key_field = showPrimaryKeyField;
                    if (Object.keys(templates).length) {
                        clientConfig.form.templates = deepClone(templates);
                    } else if (clientConfig.form && typeof clientConfig.form === 'object') {
                        delete clientConfig.form.templates;
                    }
                } else {
                    formTemplates = {};
                    requiresCustomFieldHtml = false;
                }

                inlineEditFields = {};
                var inlineFormOnly = Array.isArray(meta.inline_edit) ? meta.inline_edit : [];
                if (!inlineFormOnly.length && Array.isArray(clientConfig.inline_edit)) {
                    inlineFormOnly = clientConfig.inline_edit;
                }
                inlineFormOnly.forEach(function(field) {
                    if (field) {
                        inlineEditFields[String(field)] = true;
                    }
                });

                if (!Array.isArray(clientConfig.inline_edit) || !clientConfig.inline_edit.length) {
                    clientConfig.inline_edit = inlineFormOnly.slice();
                }

                if (Array.isArray(formConfig.all_columns) && formConfig.all_columns.length) {
                    baseColumns = formConfig.all_columns.slice();
                } else if (columnsCache.length) {
                    baseColumns = columnsCache.slice();
                }

                if (meta.table && typeof meta.table === 'object') {
                    clientConfig.table_meta = meta.table;
                }

                allowBatchDeleteButton = false;
                batchDeleteEnabled = false;
                metaInitialized = true;
                return;
            }

            if (Object.prototype.hasOwnProperty.call(meta, 'soft_delete')) {
                clientConfig.soft_delete = meta.soft_delete;
            }

            if (meta.row_ordering && typeof meta.row_ordering === 'object') {
                rowOrderingConfig = deepClone(meta.row_ordering);
                clientConfig.row_ordering = deepClone(meta.row_ordering);
            } else {
                rowOrderingConfig = { enabled: false, column: null };
                clientConfig.row_ordering = rowOrderingConfig;
            }

            if (Array.isArray(meta.columns)) {
                columnsCache = meta.columns;
            }

            if (typeof meta.primary_key === 'string' && meta.primary_key.length) {
                primaryKeyColumn = meta.primary_key;
            }

            columnLabels = meta.labels && typeof meta.labels === 'object' ? meta.labels : {};
            columnClasses = meta.column_classes && typeof meta.column_classes === 'object' ? meta.column_classes : {};
            columnWidths = meta.column_widths && typeof meta.column_widths === 'object' ? meta.column_widths : {};

            if (meta.form && typeof meta.form === 'object') {
                var liveTemplates = meta.form.templates && typeof meta.form.templates === 'object'
                    ? deepClone(meta.form.templates)
                    : {};
                formConfig = {
                    layouts: meta.form.layouts && typeof meta.form.layouts === 'object' ? meta.form.layouts : {},
                    default_tabs: meta.form.default_tabs && typeof meta.form.default_tabs === 'object' ? meta.form.default_tabs : {},
                    behaviours: meta.form.behaviours && typeof meta.form.behaviours === 'object' ? meta.form.behaviours : {},
                    labels: meta.form.labels && typeof meta.form.labels === 'object' ? meta.form.labels : {},
                    all_columns: Array.isArray(meta.form.all_columns) ? meta.form.all_columns : [],
                    sections: meta.form.sections && typeof meta.form.sections === 'object' ? meta.form.sections : {},
                    templates: liveTemplates,
                    show_primary_key_field: showPrimaryKeyField
                };
                formTemplates = liveTemplates;
                requiresCustomFieldHtml = Object.keys(liveTemplates).length > 0;
                clientConfig.form = $.extend(true, {}, meta.form);
                clientConfig.form.show_primary_key_field = showPrimaryKeyField;
                if (Object.keys(liveTemplates).length) {
                    clientConfig.form.templates = deepClone(liveTemplates);
                } else if (clientConfig.form && typeof clientConfig.form === 'object') {
                    delete clientConfig.form.templates;
                }
            } else {
                formTemplates = {};
                requiresCustomFieldHtml = false;
                formConfig = {
                    layouts: {},
                    default_tabs: {},
                    behaviours: {},
                    labels: {},
                    all_columns: [],
                    sections: {},
                    templates: {},
                    show_primary_key_field: showPrimaryKeyField
                };
                delete clientConfig.form;
            }

            // Inline edit fields (fallback to client config if meta missing/empty)
            inlineEditFields = {};
            var inlineArr = Array.isArray(meta.inline_edit) ? meta.inline_edit : [];
            if (!inlineArr.length && Array.isArray(clientConfig.inline_edit)) {
                inlineArr = clientConfig.inline_edit;
            }
            if (!Array.isArray(clientConfig.inline_edit) || clientConfig.inline_edit.length === 0) {
                clientConfig.inline_edit = inlineArr.slice();
            }
            inlineArr.forEach(function(f){ if (f) { inlineEditFields[String(f)] = true; } });
            if (Array.isArray(formConfig.all_columns) && formConfig.all_columns.length) {
                baseColumns = formConfig.all_columns.slice();
            } else {
                baseColumns = columnsCache.slice();
            }

            var tableMeta = meta.table && typeof meta.table === 'object' ? meta.table : {};
            clientConfig.table_meta = tableMeta;
            bulkActions = Array.isArray(tableMeta.bulk_actions) ? tableMeta.bulk_actions : [];

            addEnabled = tableMeta.hasOwnProperty('add') ? !!tableMeta.add : true;
            viewEnabled = tableMeta.hasOwnProperty('view') ? !!tableMeta.view : true;
            editEnabled = tableMeta.hasOwnProperty('edit') ? !!tableMeta.edit : true;
            deleteEnabled = tableMeta.hasOwnProperty('delete') ? !!tableMeta.delete : true;
            duplicateEnabled = !!tableMeta.duplicate;
            if (tableMeta.hasOwnProperty('delete_confirm')) {
                deleteConfirm = !!tableMeta.delete_confirm;
            }
            var batchDeleteButtonEnabled = tableMeta.hasOwnProperty('batch_delete_button')
                ? !!tableMeta.batch_delete_button
                : !!tableMeta.batch_delete;

            allowBatchDeleteButton = batchDeleteButtonEnabled && deleteEnabled;
            var hasBulkActions = Array.isArray(bulkActions) && bulkActions.length > 0;
            batchDeleteEnabled = allowBatchDeleteButton || hasBulkActions;
            if (!batchDeleteEnabled) {
                clearSelection();
            }

            clientConfig.link_buttons = Array.isArray(meta.link_buttons)
                ? deepClone(meta.link_buttons)
                : [];
            clientConfig.multi_link_buttons = Array.isArray(meta.multi_link_buttons)
                ? deepClone(meta.multi_link_buttons)
                : [];
            clientConfig.action_button_sequence = Array.isArray(meta.action_button_sequence)
                ? meta.action_button_sequence.slice()
                : [];

            updateMetaContainer(tableMeta);
            updateBatchDeleteButtonState();
            refreshSelectAllState();
            // sort disabled list from meta
            sortDisabled = {};
            if (Array.isArray(meta.sort_disabled)) {
                meta.sort_disabled.forEach(function(col){ if (col) { sortDisabled[String(col)] = true; } });
            }
            applyHeaderMetadata();
            // Read initial sort from meta and sync client config
            if (Array.isArray(meta.order_by)) {
                orderBy = meta.order_by.slice();
                clientConfig.order_by = orderBy.slice();
            } else {
                orderBy = [];
                clientConfig.order_by = [];
            }
            syncSortStateFromOrderBy();
            updateSortIndicators();
            ensureSortHandlers();

            if (Array.isArray(meta.limit_options) && meta.limit_options.length) {
                perPageOptions = meta.limit_options;
                clientConfig.limit_options = meta.limit_options;
            }

            if (!metaInitialized) {
                var defaultLimit = meta.default_limit;
                if (typeof defaultLimit === 'number' && defaultLimit > 0) {
                    perPage = defaultLimit;
                }
                clientConfig.per_page = perPage;
            }

            if (meta.search && (Array.isArray(meta.search.columns) || Array.isArray(meta.search.available))) {
                searchConfig = {
                    columns: Array.isArray(meta.search.columns) ? meta.search.columns : [],
                    available: Array.isArray(meta.search.available) ? meta.search.available : [],
                    default: meta.search.default || null,
                };
                searchHidden = !!meta.search.hidden;
                clientConfig.search_columns = meta.search.columns;
                clientConfig.search_default = meta.search.default || null;
                clientConfig.hide_search = searchHidden;

                // Only apply default search column on initial load.
                if (!metaInitialized && !currentSearchColumn && typeof searchConfig.default === 'string' && searchConfig.default !== '') {
                    currentSearchColumn = searchConfig.default;
                }

                if (searchHidden) {
                    removeSearchControls();
                } else {
                    ensureSearchControls();
                }
                if (searchSelect) {
                    searchSelect.val(currentSearchColumn || '');
                }
            } else {
                searchConfig = { columns: [], default: null };
                searchHidden = !!clientConfig.hide_search;
                if (searchHidden) {
                    removeSearchControls();
                } else {
                    ensureSearchControls();
                }
            }

            var nestedMeta = Array.isArray(meta.nested_tables)
                ? meta.nested_tables
                : (Array.isArray(clientConfig.nested_tables) ? clientConfig.nested_tables : []);
            nestedTablesConfig = Array.isArray(nestedMeta) ? deepClone(nestedMeta) : [];
            clientConfig.nested_tables = deepClone(nestedTablesConfig);

            if (meta.query_builder && typeof meta.query_builder === 'object') {
                hydrateQueryBuilderFromMeta(meta.query_builder);
            }

            renderSummaries(meta.summaries || []);
            refreshTooltips();

            metaInitialized = true;
        }

        function removeSearchControls() {
            if (searchGroup) {
                searchGroup.remove();
            }

            searchGroup = null;
            searchInput = null;
            searchSelect = null;
            searchButton = null;
            clearButton = null;
        }

        function ensureSearchControls() {
            if (formOnlyMode) {
                return;
            }

            if (searchHidden) {
                removeSearchControls();
                return;
            }

            if (!toolbar.length) {
                return;
            }

            // If already initialized, don't rebuild
            if (searchGroup) {
                return;
            }

            searchGroup = $('<div class="input-group fastcrud-search-group" style="max-width: 24rem;"></div>');

            // Always render the select and include an "All" option first
            searchSelect = $('<select class="form-select"></select>');
            // "All" option: empty value so it maps to null in state
            var allOption = $('<option></option>').attr('value', '').text(t('all_columns', 'All Columns'));
            if (!currentSearchColumn) {
                allOption.attr('selected', 'selected');
            }
            searchSelect.append(allOption);

            // If configured list is provided, use it strictly; otherwise fallback to visible/available
            var optionOrder = (Array.isArray(searchConfig.columns) && searchConfig.columns.length)
                ? searchConfig.columns
                : (searchConfig.available || []);

            $.each(optionOrder, function(_, column) {
                var option = $('<option></option>').attr('value', column).text(makeLabel(column));
                if (column === currentSearchColumn) {
                    option.attr('selected', 'selected');
                }
                searchSelect.append(option);
            });
            searchSelect.on('change', function() {
                var val = $(this).val();
                currentSearchColumn = val ? String(val) : undefined; // omit from request when "All"
                markFiltersDirty();
            });
            searchGroup.append(searchSelect);

            searchInput = $('<input type="search" class="form-control">')
                .attr('placeholder', t('search_placeholder', 'Search...'))
                .attr('aria-label', t('search', 'Search'));
            searchInput.on('keydown', function(event) {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    triggerSearch();
                }
            });

            searchGroup.append(searchInput);

            var searchButtonClass = getStyleClass('search_button_class', 'btn btn-outline-primary');
            searchButton = $('<button type="button"></button>').text(t('search', 'Search')).addClass(searchButtonClass).addClass('fastcrud-search-btn');
            searchButton.on('click', function() {
                triggerSearch();
            });

            var clearButtonClass = getStyleClass('search_clear_button_class', 'btn btn-outline-secondary');
            clearButton = $('<button type="button"></button>').text(t('clear', 'Clear')).addClass(clearButtonClass).addClass('fastcrud-search-clear-btn');
            clearButton.on('click', function() {
                currentSearchTerm = '';
                if (searchInput) {
                    searchInput.val('');
                }
                markFiltersDirty();
                loadTableData(1);
            });

            searchGroup.append(searchButton).append(clearButton);

            toolbar.append(searchGroup);
        }

        function updateMetaContainer(tableMeta) {
            if (formOnlyMode) {
                if (metaContainer && metaContainer.length) {
                    metaContainer.empty().addClass('d-none');
                }
                batchDeleteButton = null;
                return;
            }

            tableMeta = tableMeta && typeof tableMeta === 'object' ? tableMeta : {};
            if (!metaContainer || typeof metaContainer.length === 'undefined' || !metaContainer.length) {
                return;
            }

            metaContainer.empty();

            var hideTitle = !!(tableMeta && tableMeta.hide_title);
            var hasMeta = !hideTitle && tableMeta && (tableMeta.title || tableMeta.icon || tableMeta.tooltip);
            if (hasMeta) {
                var wrapper = $('<div class="d-flex align-items-center gap-2"></div>');

                if (tableMeta.icon) {
                    wrapper.append($('<i></i>').addClass(tableMeta.icon));
                }

                if (tableMeta.title) {
                    var title = $('<h5 class="mb-0"></h5>').text(tableMeta.title);
                    if (tableMeta.tooltip) {
                        title.attr('title', tableMeta.tooltip).attr('data-bs-toggle', 'tooltip');
                    }
                    wrapper.append(title);
                } else if (tableMeta.tooltip) {
                    wrapper.append($('<span class="text-muted"></span>').text(tableMeta.tooltip));
                }

                metaContainer.append(wrapper);
            }

            var utilitiesWrapper = $('<div class="d-flex flex-wrap align-items-center gap-2 w-100"></div>');
            metaContainer.append(utilitiesWrapper);

            var viewControlsWrapper = null;
            if (filtersEnabled) {
                viewControlsWrapper = $('<div class="d-flex flex-wrap align-items-stretch gap-2 fastcrud-view-controls"></div>');
                utilitiesWrapper.append(viewControlsWrapper);

                var savedViewGroup = $('<div class="input-group input-group-sm fastcrud-saved-view-group"></div>');
                viewControlsWrapper.append(savedViewGroup);

                viewSelect = $('<select class="form-select fastcrud-view-select"></select>')
                    .attr('aria-label', t('saved_views', 'Saved views'));
                viewSelect.on('change', function() {
                    applySavedViewByName($(this).val() ? String($(this).val()) : '');
                });
                savedViewGroup.append(viewSelect);

                deleteViewButton = $('<button type="button" class="btn btn-outline-danger"></button>')
                    .attr('title', t('delete_selected_view', 'Delete selected view'))
                    .attr('aria-label', t('delete_selected_view', 'Delete selected view'));
                deleteViewButton.append($('<i aria-hidden="true"></i>').addClass(dismissIconClass));
                deleteViewButton.append($('<span class="visually-hidden"></span>').text(t('delete', 'Delete')));
                deleteViewButton.on('click', function() {
                    deleteCurrentView();
                });
                savedViewGroup.append(deleteViewButton);

                filtersButton = $('<button type="button" class="fastcrud-open-query-builder align-self-stretch"></button>');
                filtersButton.append(document.createTextNode(t('filters', 'Filters') + ' '));
                filtersButton.append('<span class="badge bg-primary ms-2 fastcrud-filter-count d-none"></span>');
                filtersButton.addClass(getStyleClass('filters_button_class', 'btn btn-sm btn-outline-secondary'));
                filtersButtonBadge = filtersButton.find('.fastcrud-filter-count');
                filtersButton.on('click', function() {
                    openQueryBuilderModal();
                });
                viewControlsWrapper.append(filtersButton);
            }

            var actionsWrapper = $('<div class="d-flex align-items-center gap-2 ms-auto"></div>');
            utilitiesWrapper.append(actionsWrapper);
            var hasActions = false;

            function buildToolbarActionElement(actionMeta) {
                if (!actionMeta || typeof actionMeta !== 'object') {
                    return null;
                }

                var href = typeof actionMeta.url === 'string' ? actionMeta.url.trim() : '';
                if (!href.length) {
                    return null;
                }

                var classListRaw = typeof actionMeta.button_class === 'string'
                    ? actionMeta.button_class
                    : '';
                var classParts = classListRaw.trim().length
                    ? classListRaw.trim().split(/\s+/)
                    : [];
                var options = (actionMeta.options && typeof actionMeta.options === 'object')
                    ? $.extend(true, {}, actionMeta.options)
                    : {};

                if (classParts.indexOf('btn') === -1) {
                    classParts.unshift('btn');
                }
                if (classParts.indexOf('fastcrud-link-btn') === -1) {
                    classParts.push('fastcrud-link-btn');
                }
                if (classParts.indexOf('fastcrud-toolbar-action') === -1) {
                    classParts.push('fastcrud-toolbar-action');
                }

                if (Object.prototype.hasOwnProperty.call(options, 'class')) {
                    var extraClass = String(options['class'] || '').trim();
                    delete options['class'];
                    if (extraClass.length) {
                        extraClass.split(/\s+/).forEach(function(part) {
                            if (!part.length || classParts.indexOf(part) !== -1) {
                                return;
                            }
                            classParts.push(part);
                        });
                    }
                }

                var button = $('<a></a>').attr('href', href).addClass(classParts.join(' '));
                var hasTitle = false;
                var hasAriaLabel = false;
                var hasRole = false;

                Object.keys(options).forEach(function(optionKey) {
                    if (!Object.prototype.hasOwnProperty.call(options, optionKey)) {
                        return;
                    }
                    var attrName = String(optionKey);
                    if (!/^[A-Za-z0-9_:-]+$/.test(attrName)) {
                        return;
                    }
                    var lowerName = attrName.toLowerCase();
                    if (lowerName === 'href') {
                        return;
                    }

                    var attrValue = options[optionKey];
                    if (attrValue === null || typeof attrValue === 'undefined') {
                        return;
                    }
                    button.attr(attrName, attrValue);

                    if (lowerName === 'title') {
                        hasTitle = true;
                    } else if (lowerName === 'aria-label') {
                        hasAriaLabel = true;
                    } else if (lowerName === 'role') {
                        hasRole = true;
                    }
                });

                var labelText = typeof actionMeta.label === 'string'
                    ? actionMeta.label.trim()
                    : '';
                var iconClass = typeof actionMeta.icon === 'string'
                    ? actionMeta.icon.trim()
                    : '';
                var hasIcon = false;
                if (iconClass.length) {
                    var iconEl = $('<i aria-hidden="true"></i>').addClass('fastcrud-link-icon');
                    iconClass.split(/\s+/).forEach(function(part) {
                        if (!part.length) {
                            return;
                        }
                        iconEl.addClass(part);
                    });
                    button.append(iconEl);
                    hasIcon = true;
                }

                if (labelText.length) {
                    var labelSpan = $('<span class="fastcrud-link-btn-text"></span>').text(labelText);
                    if (hasIcon) {
                        labelSpan.addClass('ms-1');
                    }
                    button.append(labelSpan);
                } else if (!hasIcon) {
                    button.append($('<span class="visually-hidden"></span>').text(t('open_link', 'Open link')));
                }

                if (!hasTitle && labelText.length) {
                    button.attr('title', labelText);
                }
                if (!hasAriaLabel) {
                    button.attr('aria-label', labelText.length ? labelText : t('open_link', 'Open link'));
                }
                if (!hasRole) {
                    button.attr('role', 'button');
                }

                return button;
            }

            var toolbarActionsList = Array.isArray(tableMeta.toolbar_actions)
                ? tableMeta.toolbar_actions
                : [];
            toolbarActionsList.forEach(function(actionMeta) {
                var actionEl = buildToolbarActionElement(actionMeta);
                if (!actionEl) {
                    return;
                }
                actionsWrapper.append(actionEl);
                hasActions = true;
            });

            var toolbarHtmlList = Array.isArray(tableMeta.toolbar_html)
                ? tableMeta.toolbar_html
                : [];
            toolbarHtmlList.forEach(function(htmlMeta) {
                var html = '';
                if (typeof htmlMeta === 'string') {
                    html = htmlMeta;
                } else if (htmlMeta && typeof htmlMeta === 'object' && typeof htmlMeta.html === 'string') {
                    html = htmlMeta.html;
                }

                html = html.trim();
                if (!html.length) {
                    return;
                }

                var htmlEl = $(html);
                if (!htmlEl.length) {
                    return;
                }

                actionsWrapper.append(htmlEl);
                hasActions = true;
            });

            if (allowBatchDeleteButton) {
                var batchDeleteButtonClass = getStyleClass('batch_delete_button_class', 'btn btn-sm btn-danger');
                var batchDeleteEl = $('<button type="button" disabled></button>')
                    .addClass(batchDeleteButtonClass)
                    .addClass('fastcrud-batch-delete-btn d-none')
                    .attr('title', t('delete_selected_title', 'Delete selected records'))
                    .attr('aria-label', t('delete_selected_title', 'Delete selected records'))
                    .text(t('delete_selected', 'Delete Selected'));
                actionsWrapper.append(batchDeleteEl);
                hasActions = true;
            }

            var localBulkActions = Array.isArray(bulkActions) ? bulkActions : [];
            if (localBulkActions.length) {
                var bulkWrapper = $('<div class="d-flex align-items-center gap-2 fastcrud-bulk-actions"></div>');
                var bulkSelect = $('<select class="form-select form-select-sm fastcrud-bulk-action-select"></select>');
                bulkSelect.append($('<option></option>').attr('value', '').text(t('bulk_actions', 'Bulk actions')));

                localBulkActions.forEach(function(action, index) {
                    if (!action || typeof action !== 'object') {
                        return;
                    }

                    var label = '';
                    if (typeof action.label === 'string' && action.label.trim() !== '') {
                        label = action.label.trim();
                    } else if (typeof action.name === 'string' && action.name.trim() !== '') {
                        label = action.name.trim();
                    } else {
                        label = 'Action ' + (index + 1);
                    }

                    bulkSelect.append(
                        $('<option></option>').attr('value', String(index)).text(label)
                    );
                });

                bulkWrapper.append(bulkSelect);
                var bulkApplyButtonClass = getStyleClass('bulk_apply_button_class', 'btn btn-sm btn-outline-primary');
                var bulkApplyBtn = $('<button type="button" disabled></button>')
                    .text(t('apply', 'Apply'))
                    .addClass(bulkApplyButtonClass)
                    .addClass('fastcrud-bulk-apply-btn');
                bulkWrapper.append(bulkApplyBtn);
                actionsWrapper.append(bulkWrapper);
                hasActions = true;
            }

            if (tableMeta.export_csv) {
                var exportCsvClass = getStyleClass('export_csv_button_class', 'btn btn-sm btn-outline-secondary');
                var exportCsvBtn = $('<button type="button"></button>')
                    .addClass(exportCsvClass)
                    .addClass('fastcrud-export-csv-btn')
                    .attr('title', t('export_csv_title', 'Export as CSV'))
                    .attr('aria-label', t('export_csv_title', 'Export as CSV'))
                    .text(t('export_csv', 'Export CSV'));
                actionsWrapper.append(exportCsvBtn);
                hasActions = true;
            }

            if (addEnabled) {
                var addButtonClass = getStyleClass('add_button_class', 'btn btn-sm btn-success');
                var addButton = $('<button type="button"></button>')
                    .addClass(addButtonClass)
                    .addClass('fastcrud-add-btn')
                    .attr('title', t('add_new_record', 'Add new record'))
                    .attr('aria-label', t('add_new_record', 'Add new record'))
                    .append('<i class="fas fa-plus"></i> ')
                    .append(document.createTextNode(t('add', 'Add')));

                actionsWrapper.append(addButton);
                hasActions = true;
            }

            renderSavedViewsSelect();
            updateViewDeleteState();
            updateQueryBuilderBadge();

            if (hasActions) {
                batchDeleteButton = actionsWrapper.find('.fastcrud-batch-delete-btn');
            } else {
                batchDeleteButton = null;
            }

            if (metaContainer.children().length) {
                metaContainer.removeClass('d-none');
            } else {
                metaContainer.addClass('d-none');
            }

            updateBulkActionState();
        }

        function applyHeaderMetadata() {
            var headerCells = table.children('thead').find('th').not('.fastcrud-actions, .fastcrud-select-header, .fastcrud-nested, .fastcrud-number-header, .fastcrud-row-order-header');
            headerCells.each(function(index) {
                var column = columnsCache[index];
                if (!column) {
                    return;
                }

                var cell = $(this);
                cell.empty();
                var label = $('<span class="fastcrud-sort-label"></span>').text(makeLabel(column));
                cell.append(label);
                // Mark sortable only when not disabled
                if (!sortDisabled[column]) {
                    cell.addClass('fastcrud-sortable');
                } else {
                    cell.removeClass('fastcrud-sortable');
                }
                applyWidthToElement(cell, columnWidths[column]);
            });
        }

        function normalizeFieldForHeader(field) {
            if (!field) { return ''; }
            return String(field).replace(/\./g, '__');
        }

        function denormalizeHeaderColumn(column) {
            if (!column) { return ''; }
            return String(column).replace(/__/g, '.');
        }

        function findOrderIndex(column) {
            if (!Array.isArray(orderBy) || !orderBy.length) { return -1; }
            for (var i = 0; i < orderBy.length; i++) {
                var f = String(orderBy[i].field || '');
                if (normalizeFieldForHeader(f) === column) { return i; }
            }
            return -1;
        }

        function getDirectionForColumn(column) {
            var idx = findOrderIndex(column);
            if (idx === -1) { return null; }
            var dir = String(orderBy[idx].direction || '').toLowerCase();
            return (dir === 'asc' || dir === 'desc') ? dir : null;
        }

        function setOrder(column, direction, additive) {
            // column is header-normalized; store denormalized in config
            if (!column) { return; }
            var field = denormalizeHeaderColumn(column);
            var dir = String(direction || 'asc').toUpperCase();
            if (dir !== 'ASC' && dir !== 'DESC') { dir = 'ASC'; }

            if (additive) {
                var idx = findOrderIndex(column);
                if (idx >= 0) {
                    orderBy[idx] = { field: field, direction: dir };
                } else {
                    orderBy.push({ field: field, direction: dir });
                }
            } else {
                orderBy = [{ field: field, direction: dir }];
            }
            clientConfig.order_by = orderBy.slice();
            markFiltersDirty();
            syncSortStateFromOrderBy();
        }

        function updateSortIndicators() {
            var headerCells = table.children('thead').find('th').not('.fastcrud-actions, .fastcrud-select-header, .fastcrud-nested, .fastcrud-number-header, .fastcrud-row-order-header');
            headerCells.each(function(index) {
                var cell = $(this);
                var column = columnsCache[index];
                if (!column) { return; }
                cell.find('.fastcrud-sort-indicator').remove();
                var dir = getDirectionForColumn(column);
                if (dir === 'asc') {
                    cell.append('<span class="fastcrud-sort-indicator" aria-hidden="true">▲</span>');
                    cell.attr('aria-sort', 'ascending');
                } else if (dir === 'desc') {
                    cell.append('<span class="fastcrud-sort-indicator" aria-hidden="true">▼</span>');
                    cell.attr('aria-sort', 'descending');
                } else {
                    cell.removeAttr('aria-sort');
                }
            });
        }

        var sortHandlersBound = false;
        function ensureSortHandlers() {
            if (sortHandlersBound) { return; }
            table.on('click.fastcrudSort', 'thead th.fastcrud-sortable', function(event) {
                event.preventDefault();
                event.stopPropagation();
                var cell = $(this);
                var column = String(cell.attr('data-column') || '').trim();
                if (!column) {
                    // Fallback: derive from index
                    var index = cell.index();
                    column = columnsCache[index] || '';
                }
                if (!column) { return false; }
                if (sortDisabled[column]) { return false; }
                var current = getDirectionForColumn(column);
                var next = (current === 'asc') ? 'desc' : 'asc';
                var additive = !!(event.shiftKey);
                setOrder(column, next || 'asc', additive);
                updateSortIndicators();
                loadTableData(1);
                return false;
            });
            sortHandlersBound = true;
        }

        function applyWidthToElement(element, widthValue) {
            if (!element || !element.length) {
                return;
            }

            element.css('width', '');

            if (!widthValue) {
                return;
            }

            var width = String(widthValue).trim();
            if (!width) {
                return;
            }

            if (/\s/.test(width)) {
                width.split(/\s+/).forEach(function(token) {
                    if (token) {
                        element.addClass(token);
                    }
                });
                return;
            }

            var lower = width.toLowerCase();
            var units = ['px', 'rem', 'em', '%', 'vw', 'vh'];
            var useStyle = false;
            for (var index = 0; index < units.length; index++) {
                var unit = units[index];
                if (lower.slice(-unit.length) === unit) {
                    useStyle = true;
                    break;
                }
            }

            if (!useStyle && lower.indexOf('calc(') !== -1) {
                useStyle = true;
            }

            if (useStyle) {
                element.css('width', width);
            } else {
                element.addClass(width);
            }
        }

        function renderSummaries(summaries) {
            if (!summaryFooter.length) {
                return;
            }

            summaryFooter.empty();

            if (!Array.isArray(summaries) || !summaries.length || !columnsCache.length) {
                summaryFooter.addClass('d-none');
                return;
            }

            summaryFooter.removeClass('d-none');

            $.each(summaries, function(_, summary) {
                var row = $('<tr ></tr>');
                var targetColumn = summary.column;
                var labelText = summary.label || makeLabel(targetColumn);
                var renderedValue = summary.value === null || typeof summary.value === 'undefined' || summary.value === ''
                    ? '—'
                    : String(summary.value);

                if (isRowOrderingActive()) {
                    row.append('<td class="fastcrud-row-order-cell">&nbsp;</td>');
                }

                if (hasNestedTablesConfigured()) {
                    row.append('<td class="fastcrud-nested-cell">&nbsp;</td>');
                }

                if (batchDeleteEnabled) {
                    row.append('<td class="text-center fastcrud-select-cell">&nbsp;</td>');
                }

                if (numbersEnabled) {
                    row.append('<td class="fastcrud-number-cell">&nbsp;</td>');
                }

                $.each(columnsCache, function(columnIndex, column) {
                    var cell = $('<td></td>');
                    applyWidthToElement(cell, columnWidths[column]);

                    if (columnIndex === 0) {
                        if (column === targetColumn) {
                            cell.text(labelText + ': ' + renderedValue).addClass('fw-semibold');
                        } else {
                            cell.text(labelText).addClass('text-muted');
                        }
                    } else if (column === targetColumn) {
                        cell.text(renderedValue).addClass('fw-semibold');
                    } else {
                        cell.html('&nbsp;');
                    }

                    row.append(cell);
                });

                row.append('<td class="text-end fastcrud-actions-cell"><div class="fastcrud-actions-stack">&nbsp;</div></td>');
                summaryFooter.append(row);
            });
        }

        function refreshTooltips() {
            if (!window.bootstrap || !bootstrap.Tooltip) {
                return;
            }

            var tooltipTargets = table.find('[data-bs-toggle="tooltip"]').get();
            tooltipTargets.forEach(function(target) {
                bootstrap.Tooltip.getOrCreateInstance(target);
            });

            var metaTargets = metaContainer.find('[data-bs-toggle="tooltip"]').get();
            metaTargets.forEach(function(target) {
                bootstrap.Tooltip.getOrCreateInstance(target);
            });
        }

        function withRichEditorAssets(callback) {
            if (typeof callback !== 'function') {
                return;
            }

            if (typeof window.tinymce !== 'undefined') {
                richEditorState.loaded = true;
                callback();
                return;
            }

            richEditorState.queue.push(callback);

            if (richEditorState.loading) {
                return;
            }

            richEditorState.loading = true;

            var script = document.createElement('script');
            script.src = richEditorState.scriptUrl;
            script.referrerPolicy = 'no-referrer';
            script.onload = function() {
                richEditorState.loaded = true;
                richEditorState.loading = false;
                var queued = richEditorState.queue.slice();
                richEditorState.queue.length = 0;
                queued.forEach(function(fn) {
                    try {
                        fn();
                    } catch (error) {
                        console.error(error);
                    }
                });
            };
            script.onerror = function() {
                richEditorState.loading = false;
                console.error('FastCrud: failed to load TinyMCE assets from ' + richEditorState.scriptUrl);
                richEditorState.queue.length = 0;
            };

            document.head.appendChild(script);
        }

        function initializeRichEditors(container, visible) {
            if (!container || !container.length) {
                return;
            }

            var editors = container.is('textarea') ? container.filter('.fastcrud-rich-editor') : container.find('textarea.fastcrud-rich-editor');
            if (!editors.length) {
                return;
            }

            if (!visible) {
                editors.each(function() {
                    var element = this;
                    whenWidgetVisible(element, function() { initializeRichEditors($(element), true); });
                });
                return;
            }
            withRichEditorAssets(function() {
                if (!window.tinymce || typeof window.tinymce.init !== 'function') {
                    return;
                }

                editors.each(function() {
                    var textarea = $(this);
                    var element = textarea.get(0);
                    if (!element || !element.isConnected || disposed) {
                        return;
                    }

                    if (element.id) {
                        var existingEditor = window.tinymce.get(element.id);
                        if (existingEditor) {
                            existingEditor.remove();
                        }
                    }

                    var overrides = textarea.data('fastcrudEditorConfig');
                    var config = $.extend(true, {}, richEditorState.baseConfig || {});
                    if (overrides && typeof overrides === 'object') {
                        config = $.extend(true, config, overrides);
                    }

                    if (config.selector) {
                        delete config.selector;
                    }
                    if (config.target && config.target !== element) {
                        delete config.target;
                    }

                    config.target = element;

                    var existingSetup = config.setup;
                    config.setup = function(editor) {
                        editor.on('change keyup blur', function() {
                            textarea.val(editor.getContent());
                        });
                        if (typeof existingSetup === 'function') {
                            existingSetup(editor);
                        }
                    };

                    if (!config.images_upload_url) {
                        config.images_upload_url = richEditorConfig.upload_url || window.location.pathname;
                    }
                    config.images_upload_credentials = true;

                    if (typeof config.images_upload_handler !== 'function') {
                        config.images_upload_handler = function(blobInfo, success, failure, progress) {
                            var uploadUrl = config.images_upload_url || richEditorConfig.upload_url || window.location.pathname;
                            var xhr = new XMLHttpRequest();
                            xhr.open('POST', uploadUrl);
                            xhr.withCredentials = true;

                            xhr.onload = function() {
                                if (xhr.status < 200 || xhr.status >= 300) {
                                    failure('Upload failed with status ' + xhr.status);
                                    return;
                                }
                                var response;
                                var rawResponse = xhr.responseText || '';
                                try {
                                    response = JSON.parse(rawResponse || '{}');
                                } catch (error) {
                                    if (window.console && typeof window.console.error === 'function') {
                                        console.error('FastCrud TinyMCE upload JSON parse error', error, rawResponse);
                                    }
                                    failure('Upload returned invalid JSON.');
                                    return;
                                }
                                if (!response || response.success !== true || !response.location) {
                                    var message = response && response.error ? response.error : 'Upload failed.';
                                    failure(message);
                                    return;
                                }
                                success(response.location);
                            };

                            xhr.onerror = function() {
                                failure('Upload failed due to a network error.');
                            };

                            if (xhr.upload && typeof progress === 'function') {
                                xhr.upload.onprogress = function(event) {
                                    if (event.lengthComputable) {
                                        progress((event.loaded / event.total) * 100);
                                    }
                                };
                            }

                            var formData = new FormData();
                            formData.append('file', blobInfo.blob(), blobInfo.filename());
                            formData.append('fastcrud_ajax', '1');
                            formData.append('action', 'upload_image');
                            if (tableName) {
                                formData.append('table', tableName);
                            }
                            if (tableId) {
                                formData.append('id', tableId);
                            }

                            xhr.send(formData);
                        };
                    }

                    window.tinymce.init(config);
                });
            });
        }

        function destroyRichEditors(container) {
            if (!container || !container.length) {
                return;
            }

            if (!window.tinymce || !window.tinymce.editors) {
                return;
            }

            var editors = Array.prototype.slice.call(window.tinymce.editors || []);
            editors.forEach(function(editor) {
                if (!editor) {
                    return;
                }
                var element = editor.targetElm || (typeof editor.getElement === 'function' ? editor.getElement() : null);
                if (!element) {
                    return;
                }
                if ($(element).closest(container).length) {
                    editor.remove();
                }
            });
        }

        // Note: previously had a jQuery-based builder for custom buttons here.
        // It was unused and removed to reduce dead code.

        var actionIcons = options.icons;

        // Note: previously had a jQuery-based builder for the action cell here.
        // The code now uses `buildActionCellHtml` to generate HTML strings directly.

        function triggerSearch() {
            if (!searchInput) {
                return;
            }

            currentSearchTerm = searchInput.val() || '';
            markFiltersDirty();
            loadTableData(1);
        }

        function findPrimaryKey(columns) {
            var pattern = /(^id$|_id$)/i;
            for (var index = 0; index < columns.length; index++) {
                if (pattern.test(columns[index])) {
                    return columns[index];
                }
            }

            return columns.length ? columns[0] : null;
        }

        function makeLabel(column) {
            if (columnLabels && Object.prototype.hasOwnProperty.call(columnLabels, column)) {
                return columnLabels[column];
            }

            var words = column.replace(/_/g, ' ').split(' ');

            for (var index = 0; index < words.length; index++) {
                if (words[index].length > 0) {
                    words[index] = words[index].charAt(0).toUpperCase() + words[index].slice(1);
                }
            }

            return words.join(' ');
        }

        function resolveFieldLabel(column) {
            if (formConfig.labels && Object.prototype.hasOwnProperty.call(formConfig.labels, column)) {
                var label = formConfig.labels[column];
                if (typeof label === 'string') {
                    return label;
                }
                if (label === null) {
                    return '';
                }
            }

            return makeLabel(column);
        }

        function makeSlug(value) {
            return String(value || '')
                .toLowerCase()
                .replace(/[^a-z0-9]+/g, '-')
                .replace(/^-+|-+$/g, '') || 'tab';
        }

        function resolveBehaviour(key, field, mode) {
            if (!formConfig.behaviours || !formConfig.behaviours[key]) {
                return undefined;
            }

            var definition = formConfig.behaviours[key][field];
            if (!definition) {
                return undefined;
            }

            var value = typeof definition.all !== 'undefined' ? definition.all : undefined;
            if (mode && typeof definition[mode] !== 'undefined') {
                value = definition[mode];
            }

            return value;
        }

        function resolveBehavioursForField(field, mode) {
            var behaviours = {};
            if (formConfig.behaviours && formConfig.behaviours.change_type && formConfig.behaviours.change_type[field]) {
                behaviours.change_type = formConfig.behaviours.change_type[field];
            }

            var keys = ['pass_var', 'pass_default', 'readonly', 'disabled', 'visible_if', 'editable_if', 'validation_required', 'validation_pattern', 'max_length', 'unique'];
            keys.forEach(function(key) {
                var value = resolveBehaviour(key, field, mode);
                if (typeof value !== 'undefined') {
                    behaviours[key] = value;
                }
            });

            return behaviours;
        }

        function normalizePermissionValue(value, fallback) {
            if (typeof value === 'boolean') {
                return value;
            }
            if (typeof value === 'number') {
                return value !== 0;
            }
            if (typeof value === 'string') {
                var normalized = value.trim().toLowerCase();
                if (['1', 'true', 'yes', 'y', 'on', 'enabled', 'enable', 'active'].indexOf(normalized) !== -1) {
                    return true;
                }
                if (['0', 'false', 'no', 'n', 'off', 'disabled', 'disable', 'inactive', 'hidden'].indexOf(normalized) !== -1) {
                    return false;
                }
            }

            return fallback;
        }

        function getRowFieldPermission(row, field) {
            if (!row || typeof row !== 'object' || !row.__fastcrud_field_permissions || typeof row.__fastcrud_field_permissions !== 'object') {
                return null;
            }

            var permission = row.__fastcrud_field_permissions[field];
            return permission && typeof permission === 'object' ? permission : null;
        }

        function isFieldVisibleForRow(field, mode, row) {
            var permission = getRowFieldPermission(row, field);
            if (permission && Object.prototype.hasOwnProperty.call(permission, 'visible')) {
                return normalizePermissionValue(permission.visible, true);
            }

            var value = resolveBehaviour('visible_if', field, mode);
            return normalizePermissionValue(value, true);
        }

        function isFieldEditableForRow(field, mode, row) {
            var permission = getRowFieldPermission(row, field);
            if (permission && Object.prototype.hasOwnProperty.call(permission, 'editable')) {
                return normalizePermissionValue(permission.editable, true);
            }

            var value = resolveBehaviour('editable_if', field, mode);
            return normalizePermissionValue(value, true);
        }

        function resolveSectionsForMode(mode, availableFields) {
            var sections = [];
            var indexLookup = {};
            var ordering = Array.isArray(availableFields) ? availableFields.slice() : [];
            var availableLookup = {};

            ordering.forEach(function(field) {
                availableLookup[field] = true;
            });

            function listify(source) {
                if (!source) {
                    return [];
                }
                if (Array.isArray(source)) {
                    return source.slice();
                }
                if (typeof source === 'object') {
                    return Object.keys(source).map(function(key) {
                        return source[key];
                    });
                }
                return [];
            }

            function normalizeId(rawId) {
                if (typeof rawId !== 'string') {
                    return '';
                }
                var trimmed = rawId.trim();
                if (!trimmed.length) {
                    return '';
                }
                return trimmed.replace(/[^A-Za-z0-9_-]+/g, '_').replace(/^[_-]+|[_-]+$/g, '').toLowerCase();
            }

            function collect(entries) {
                listify(entries).forEach(function(entry) {
                    if (!entry || typeof entry !== 'object') {
                        return;
                    }

                    var rawId = entry.id || entry.section;
                    var sectionId = normalizeId(rawId || '');
                    if (!sectionId.length) {
                        return;
                    }

                    var rawFields = Array.isArray(entry.fields) ? entry.fields.slice() : [];
                    if (!rawFields.length) {
                        return;
                    }

                    var filteredFields = [];
                    rawFields.forEach(function(field) {
                        if (typeof field !== 'string' || !field.length) {
                            return;
                        }
                        if (ordering.length && !availableLookup[field]) {
                            return;
                        }
                        if (filteredFields.indexOf(field) === -1) {
                            filteredFields.push(field);
                        }
                    });

                    if (!filteredFields.length) {
                        return;
                    }

                    var title = null;
                    if (typeof entry.title === 'string') {
                        var trimmedTitle = entry.title.trim();
                        title = trimmedTitle.length ? trimmedTitle : null;
                    }

                    var description = null;
                    if (typeof entry.description === 'string') {
                        var trimmedDescription = entry.description.trim();
                        description = trimmedDescription.length ? trimmedDescription : null;
                    }

                    var collapsible = !!entry.collapsible;
                    var collapsed = collapsible && !!entry.collapsed;

                    var icon = null;
                    if (typeof entry.icon === 'string') {
                        var trimmedIcon = entry.icon.trim();
                        icon = trimmedIcon.length ? trimmedIcon : null;
                    }

                    var customClass = null;
                    if (typeof entry.class === 'string') {
                        var trimmedClass = entry.class.trim();
                        customClass = trimmedClass.length ? trimmedClass : null;
                    }

                    var titleClass = null;
                    if (typeof entry.title_class === 'string') {
                        var trimmedTitleClass = entry.title_class.trim();
                        titleClass = trimmedTitleClass.length ? trimmedTitleClass : null;
                    }

                    var normalized = {
                        id: sectionId,
                        title: title,
                        description: description,
                        fields: filteredFields,
                        collapsible: collapsible,
                        collapsed: collapsed,
                        icon: icon,
                        class: customClass,
                        title_class: titleClass
                    };

                    if (Object.prototype.hasOwnProperty.call(indexLookup, sectionId)) {
                        sections[indexLookup[sectionId]] = normalized;
                    } else {
                        indexLookup[sectionId] = sections.length;
                        sections.push(normalized);
                    }
                });
            }

            if (formConfig.sections && typeof formConfig.sections === 'object') {
                collect(formConfig.sections.all);
                if (mode && Object.prototype.hasOwnProperty.call(formConfig.sections, mode)) {
                    collect(formConfig.sections[mode]);
                }
            }

            var fieldMap = {};
            sections.forEach(function(section) {
                section.fields.forEach(function(field) {
                    fieldMap[field] = section.id;
                });
            });

            return { list: sections, map: fieldMap };
        }

        function buildFormLayout(mode) {
            mode = mode || 'edit';

            var instructions = [];
            if (formConfig.layouts) {
                if (Array.isArray(formConfig.layouts.all)) {
                    instructions = instructions.concat(formConfig.layouts.all);
                }
                if (formConfig.layouts[mode] && Array.isArray(formConfig.layouts[mode])) {
                    instructions = instructions.concat(formConfig.layouts[mode]);
                }
            }

            var hasWhitelist = false;
            var whitelistOrder = [];
            var hiddenFields = {};
            var fieldTabMap = {};
            var fieldSectionHints = {};
            var tabOrder = [];
            var columnUniverse = baseColumns.length ? baseColumns.slice() : columnsCache.slice();
            var columnLookup = {};
            columnUniverse.forEach(function(field) {
                columnLookup[field] = true;
            });

            instructions.forEach(function(entry) {
                if (!entry || !Array.isArray(entry.fields)) {
                    return;
                }

                var tabName = entry.tab && String(entry.tab).length ? String(entry.tab) : null;
                var sectionName = entry.section && String(entry.section).length ? String(entry.section) : null;
                var resolvedFields = entry.fields.filter(function(field) {
                    return columnUniverse.length === 0 || columnLookup[field];
                });

                if (!resolvedFields.length) {
                    return;
                }

                if (entry.reverse) {
                    resolvedFields.forEach(function(field) {
                        hiddenFields[field] = true;
                    });
                    return;
                }

                hasWhitelist = true;
                resolvedFields.forEach(function(field) {
                    if (whitelistOrder.indexOf(field) === -1) {
                        whitelistOrder.push(field);
                    }
                    if (tabName) {
                        fieldTabMap[field] = tabName;
                        if (tabOrder.indexOf(tabName) === -1) {
                            tabOrder.push(tabName);
                        }
                    } else if (!Object.prototype.hasOwnProperty.call(fieldTabMap, field)) {
                        fieldTabMap[field] = null;
                    }
                    if (sectionName) {
                        fieldSectionHints[field] = sectionName;
                    }
                });
            });

            var ordering;
            if (hasWhitelist && whitelistOrder.length) {
                ordering = whitelistOrder.slice();
            } else {
                var fallback = columnUniverse.length ? columnUniverse : columnsCache;
                ordering = fallback.slice().filter(function(field) {
                    return !hiddenFields[field];
                });
            }

            ordering = ordering.filter(function(field) {
                if (!primaryKeyColumn || field !== primaryKeyColumn) {
                    return true;
                }
                if (!showPrimaryKeyField) {
                    return false;
                }
                var modeKey = typeof mode === 'string' ? mode.toLowerCase() : '';
                return modeKey === 'create' || modeKey === 'edit';
            });

            if (showPrimaryKeyField && primaryKeyColumn) {
                var hasPk = ordering.indexOf(primaryKeyColumn) !== -1;
                var modeKeyForPk = typeof mode === 'string' ? mode.toLowerCase() : '';
                if (!hasPk && (modeKeyForPk === 'create' || modeKeyForPk === 'edit')) {
                    ordering.unshift(primaryKeyColumn);
                }
            }

            var defaultTab = null;
            if (formConfig.default_tabs) {
                if (typeof formConfig.default_tabs.all === 'string' && formConfig.default_tabs.all.length) {
                    defaultTab = formConfig.default_tabs.all;
                }
                if (mode && typeof formConfig.default_tabs[mode] === 'string' && formConfig.default_tabs[mode].length) {
                    defaultTab = formConfig.default_tabs[mode];
                }
            }

            var sectionsInfo = resolveSectionsForMode(mode, ordering);
            var sectionMetaMap = {};
            sectionsInfo.list.forEach(function(section) {
                sectionMetaMap[section.id] = section;
            });

            Object.keys(fieldSectionHints).forEach(function(field) {
                if (!columnLookup[field]) {
                    return;
                }
                var sectionId = fieldSectionHints[field];
                if (!sectionId) {
                    return;
                }
                if (!Object.prototype.hasOwnProperty.call(sectionMetaMap, sectionId)) {
                    sectionMetaMap[sectionId] = {
                        id: sectionId,
                        title: makeLabel(sectionId),
                        description: null,
                        fields: [],
                        collapsible: false,
                        collapsed: false,
                        icon: null,
                        class: null,
                        title_class: null
                    };
                    sectionsInfo.list.push(sectionMetaMap[sectionId]);
                }
                if (sectionMetaMap[sectionId].fields.indexOf(field) === -1) {
                    sectionMetaMap[sectionId].fields.push(field);
                }
                sectionsInfo.map[field] = sectionId;
            });

            sectionsInfo.list = sectionsInfo.list.filter(function(section) {
                section.fields = ordering.filter(function(field) {
                    return sectionsInfo.map[field] === section.id;
                });
                return section.fields.length > 0;
            });

            var hasTabs = tabOrder.length > 0;
            var normalizedFields = ordering.map(function(field) {
                return {
                    name: field,
                    tab: fieldTabMap[field] || null,
                    section: sectionsInfo.map[field] || null
                };
            });

            if (hasTabs) {
                var fallbackTab = defaultTab || (tabOrder.length ? tabOrder[0] : null);
                normalizedFields = normalizedFields.map(function(item) {
                    if (!item.tab && fallbackTab) {
                        item.tab = fallbackTab;
                    }
                    if (item.tab && tabOrder.indexOf(item.tab) === -1) {
                        tabOrder.push(item.tab);
                    }
                    return item;
                });

                if (fallbackTab && tabOrder.indexOf(fallbackTab) === -1) {
                    tabOrder.unshift(fallbackTab);
                }
            } else {
                normalizedFields.forEach(function(item) {
                    if (item.tab && tabOrder.indexOf(item.tab) === -1) {
                        tabOrder.push(item.tab);
                    }
                });
            }

            return {
                fields: normalizedFields,
                tabs: tabOrder.filter(function(tab) { return !!tab; }),
                defaultTab: defaultTab,
                sections: sectionsInfo.list
            };
        }

        function interpolateTemplate(template, context) {
            if (typeof template !== 'string') {
                return template;
            }

            return template.replace(/\{([A-Za-z0-9_]+)\}/g, function(_, token) {
                if (!Object.prototype.hasOwnProperty.call(context, token)) {
                    return '';
                }

                var value = context[token];
                if (value === null || typeof value === 'undefined') {
                    return '';
                }

                return String(value);
            });
        }

        function compileClientPattern(pattern) {
            if (typeof pattern !== 'string') {
                return null;
            }

            var trimmed = pattern.trim();
            if (!trimmed.length) {
                return null;
            }

            var delimiter = trimmed.charAt(0);
            var body = trimmed;
            var flags = '';
            var closingIndex = trimmed.lastIndexOf(delimiter);

            if ((delimiter === '/' || delimiter === '#') && closingIndex > 0) {
                body = trimmed.slice(1, closingIndex);
                flags = trimmed.slice(closingIndex + 1);
            }

            try {
                return new RegExp(body, flags.replace(/[^gimuy]/g, ''));
            } catch (error) {
                return null;
            }
        }

        function clearFormAlerts() {
            editError.addClass('d-none').text('');
            editSuccess.addClass('d-none');
            clearFieldErrors();
        }

        function clearFieldErrors() {
            if (!editFieldsContainer.length) {
                return;
            }

            editFieldsContainer.find('.is-invalid').removeClass('is-invalid');
            editFieldsContainer.find('.fastcrud-field-feedback').remove();
        }

        function updateCharCounter(input) {
            var jqInput = input && input.jquery ? input : $(input || []);
            if (!jqInput.length) {
                return;
            }

            var limit = parseInt(jqInput.attr('data-fastcrud-max-length') || '', 10);
            var counter = jqInput.data && typeof jqInput.data === 'function'
                ? jqInput.data('fastcrudCharCounter')
                : null;
            if (Number.isNaN(limit) || limit < 1 || !counter || !counter.length) {
                return;
            }

            var count = String(jqInput.val() || '').length;
            counter.text(count + ' / ' + limit);
            counter.toggleClass('text-danger', count > limit);
            counter.toggleClass('text-muted', count <= limit);
        }

        function buildJsonErrorMessage(error) {
            var detail = '';
            if (error && typeof error.message === 'string') {
                detail = error.message.replace(/\s+/g, ' ').trim();
            } else if (typeof error === 'string') {
                detail = error.replace(/\s+/g, ' ').trim();
            }
            return detail ? 'Invalid JSON: ' + detail : 'Invalid JSON.';
        }

        function setInlineFieldError(input, message) {
            if (!input) {
                return;
            }

            var jqInput = input.jquery ? input : $(input);
            if (!jqInput.length) {
                return;
            }

            var text = String(message || '').trim();
            if (text === '') {
                clearInlineFieldError(jqInput);
                return;
            }

            var group = jqInput.closest('.mb-3, .form-check');
            var feedback;
            if (group.length) {
                feedback = group.find('.fastcrud-field-feedback').first();
                if (!feedback.length) {
                    feedback = $('<div class="invalid-feedback fastcrud-field-feedback" data-fastcrud-inline="1"></div>');
                    group.append(feedback);
                }
            } else {
                feedback = jqInput.siblings('.fastcrud-field-feedback').first();
                if (!feedback.length) {
                    feedback = $('<div class="invalid-feedback fastcrud-field-feedback" data-fastcrud-inline="1"></div>');
                    jqInput.after(feedback);
                }
            }

            feedback.attr('data-fastcrud-inline', '1');
            feedback.text(text);
            jqInput.addClass('is-invalid');
        }

        function clearInlineFieldError(input) {
            if (!input) {
                return;
            }

            var jqInput = input.jquery ? input : $(input);
            if (!jqInput.length) {
                return;
            }

            jqInput.removeClass('is-invalid');

            var group = jqInput.closest('.mb-3, .form-check');
            var feedback;
            if (group.length) {
                feedback = group.find('.fastcrud-field-feedback[data-fastcrud-inline="1"]').first();
            } else {
                feedback = jqInput.siblings('.fastcrud-field-feedback[data-fastcrud-inline="1"]').first();
            }

            if (feedback && feedback.length) {
                feedback.remove();
            }
        }

        function setFilePondFieldError(fileInput, valueInput, message) {
            var jqFileInput = fileInput && fileInput.jquery ? fileInput : $(fileInput || []);
            var jqValueInput = valueInput && valueInput.jquery ? valueInput : $(valueInput || []);
            var liveFileInput = $();
            var fileInputId = jqFileInput.length ? String(jqFileInput.attr('id') || '') : '';
            if (fileInputId) {
                liveFileInput = $('#' + $.escapeSelector(fileInputId));
            }
            if (!liveFileInput.length) {
                liveFileInput = jqFileInput;
            }

            var group = jqValueInput.closest('.mb-3');
            if (!group.length) {
                group = liveFileInput.closest('.mb-3');
            }
            if (!group.length) {
                group = jqFileInput.closest('.mb-3');
            }
            if (!group.length) {
                return;
            }

            var text = String(message || '').trim();

            if (text === '') {
                liveFileInput.add(jqFileInput).add(jqValueInput).removeClass('is-invalid');
                group.removeClass('fastcrud-field-invalid');
                group.removeData('fastcrudFilePondError');
                group.find('.fastcrud-filepond-feedback').remove();
                return;
            }

            group.data('fastcrudFilePondError', text);
            group.find('.fastcrud-filepond-feedback').remove();
            var feedback = $('<div class="alert alert-danger py-2 px-3 small mb-2 fastcrud-field-feedback fastcrud-filepond-feedback" data-fastcrud-inline="1" role="alert"></div>').text(text);
            var pondRoot = group.find('.filepond--root').first();
            if (pondRoot.length) {
                pondRoot.before(feedback);
            } else if (liveFileInput.length) {
                liveFileInput.before(feedback);
            } else {
                jqValueInput.before(feedback);
            }
            liveFileInput.add(jqFileInput).add(jqValueInput).addClass('is-invalid');
            group.addClass('fastcrud-field-invalid');

            [0, 50, 150].forEach(function(delay) {
                setTimeout(function() {
                    group.find('.filepond--file-status-main').each(function() {
                        var status = $(this);
                        var current = String(status.text() || '').trim();
                        if (current === '' || current === 'Error during upload' || current === 'Upload complete') {
                            status.text(text);
                        }
                    });
                }, delay);
            });
        }

        function applyFieldErrors(errors) {
            clearFieldErrors();
            if (!errors || typeof errors !== 'object') {
                return;
            }

            currentFieldErrors = errors;

            Object.keys(errors).forEach(function(field) {
                var message = errors[field];
                var selector = '[data-fastcrud-field="' + field + '"]';
                var input = editFieldsContainer.find(selector);
                if (!input.length) {
                    input = editForm.find(selector);
                }
                if (!input.length) {
                    return;
                }

                input.addClass('is-invalid');
                var attachedControls = input.data && typeof input.data === 'function'
                    ? input.data('fastcrudControls')
                    : null;
                if (attachedControls && attachedControls.length) {
                    attachedControls.addClass('is-invalid');
                }
                var feedback = $('<div class="invalid-feedback fastcrud-field-feedback"></div>').text(message);
                var group = input.closest('.mb-3');
                if (group.length) {
                    if (!group.find('.fastcrud-field-feedback').length) {
                        group.append(feedback);
                    }
                } else {
                    input.after(feedback);
                }
            });
        }

        function showFormError(message) {
            editSuccess.addClass('d-none');
            editError.text(message).removeClass('d-none');
        }

        function showLoadingRow(colspan, message) {
            var tbody = table.children('tbody');
            var row = $('<tr class="fastcrud-loading-row"></tr>');
            var cell = $('<td></td>')
                .attr('colspan', colspan)
                .addClass('text-center fastcrud-loading-placeholder');
            var wrapper = $('<div class="d-inline-flex align-items-center gap-2"></div>');
            var spinner = $('<span class="spinner-border spinner-border-sm" role="status"></span>');
            spinner.append($('<span class="visually-hidden"></span>').text(t('loading', 'Loading...')));
            wrapper.append(spinner);
            wrapper.append($('<span class="fastcrud-loading-text"></span>').text(message || t('loading', 'Loading...')));
            cell.append(wrapper);
            row.append(cell);
            tbody.html(row);
        }

        function showEmptyRow(colspan, message) {
            var tbody = table.children('tbody');
            var row = $('<tr></tr>');
            row.append(
                $('<td></td>')
                    .attr('colspan', colspan)
                    .addClass('text-center text-muted')
                    .text(message || t('no_records', 'No records found.'))
            );
            tbody.append(row);
        }

        function showError(message) {
            var tbody = table.children('tbody');
            var colspan = table.children('thead').find('th').length || 1;
            tbody.empty();
            var row = $('<tr></tr>');
            row.append(
                $('<td></td>')
                    .attr('colspan', colspan)
                    .addClass('text-danger text-center')
                    .text(message)
            );
            tbody.append(row);
        }

        function buildPagination(pagination) {
            paginationContainer.empty();
            if (!pagination) {
                if (rangeDisplay.length) {
                    rangeDisplay.text('');
                }
                return;
            }

            var current = pagination.current_page;
            var totalPages = pagination.total_pages;
            var totalRows = pagination.total_rows;

            if (rangeDisplay.length) {
                rangeDisplay
                    .removeClass('d-flex flex-wrap align-items-center gap-2 justify-content-end')
                    .addClass('text-muted small ms-auto');
            }

            if (compactPagination) {
                buildCompactPagination(pagination, current, totalPages, totalRows);
                return;
            }

            var options = perPageOptions.length ? perPageOptions : [5, 10, 25, 50, 100];
            var select = null;

            if (options.length > 1) {
                select = $('<select></select>')
                    .addClass('form-select form-select-sm border-secondary')
                    .attr('style', 'width: auto; height: 38px; padding: 0.375rem 2rem 0.375rem 0.75rem;');

                $.each(options, function(_, value) {
                    var optionValue = value;
                    var optionLabel = value;

                    if (value === 'all') {
                        optionValue = 'all';
                        optionLabel = t('all', 'All');
                    }

                    var option = $('<option></option>')
                        .attr('value', optionValue)
                        .text(optionLabel);

                    if ((value === 'all' && perPage === 0) || (value !== 'all' && parseInt(value, 10) === perPage)) {
                        option.attr('selected', 'selected');
                    }

                    select.append(option);
                });

                select.on('change', function() {
                    var selected = $(this).val();
                if (selected === 'all') {
                    perPage = 0;
                    clientConfig.per_page = 0;
                    loadTableData(1);
                    return;
                }

                var parsed = parseInt(selected, 10);
                if (!isNaN(parsed) && parsed > 0) {
                    perPage = parsed;
                    clientConfig.per_page = parsed;
                    loadTableData(1);
                }
            });

                var selectItem = $('<li class="page-item me-3"></li>').append(select);
                paginationContainer.append(selectItem);
            }

            var prevItem = $('<li class="page-item"></li>');
            if (current === 1) {
                prevItem.addClass('disabled');
            }
            prevItem.append(
                $('<a class="page-link rounded-start" href="javascript:void(0)"><span aria-hidden="true">&laquo;</span></a>')
                    .attr('aria-label', t('previous', 'Previous'))
                    .on('click', function(event) {
                        event.preventDefault();
                        event.stopPropagation();
                        if (current > 1) {
                            loadTableData(current - 1);
                        }
                        return false;
                    })
            );
            paginationContainer.append(prevItem);

            var start = Math.max(1, current - 2);
            var end = Math.min(totalPages, current + 2);

            if (start > 1) {
                paginationContainer.append(createPageItem(1, false));
                if (start > 2) {
                    paginationContainer.append($('<li class="page-item disabled"><span class="page-link">...</span></li>'));
                }
            }

            for (var pageNumber = start; pageNumber <= end; pageNumber++) {
                paginationContainer.append(createPageItem(pageNumber, pageNumber === current));
            }

            if (end < totalPages) {
                if (end < totalPages - 1) {
                    paginationContainer.append($('<li class="page-item disabled"><span class="page-link">...</span></li>'));
                }
                paginationContainer.append(createPageItem(totalPages, false));
            }

            var nextItem = $('<li class="page-item"></li>');
            if (current === totalPages) {
                nextItem.addClass('disabled');
            }
            nextItem.append(
                $('<a class="page-link rounded-end" href="javascript:void(0)"><span aria-hidden="true">&raquo;</span></a>')
                    .attr('aria-label', t('next', 'Next'))
                    .on('click', function(event) {
                        event.preventDefault();
                        event.stopPropagation();
                        if (current < totalPages) {
                            loadTableData(current + 1);
                        }
                        return false;
                    })
            );
            paginationContainer.append(nextItem);

            if (rangeDisplay.length) {
                var startRange = totalRows === 0 ? 0 : ((current - 1) * pagination.per_page) + 1;
                var endRange = totalRows === 0 ? 0 : Math.min(current * pagination.per_page, totalRows);
                rangeDisplay.text(t('showing_range', 'Showing {start}-{end} of {total}', {
                    start: startRange,
                    end: endRange,
                    total: totalRows
                }));
            }
        }

        function buildCompactPagination(pagination, current, totalPages, totalRows) {
            paginationContainer.empty();

            var target = rangeDisplay.length ? rangeDisplay : paginationContainer;
            target.empty()
                .removeClass('text-muted')
                .addClass('d-flex flex-wrap align-items-center gap-2 justify-content-end ms-auto');

            if (rangeDisplay.length) {
                target.append(
                    $('<span class="text-muted small"></span>').text(getPaginationRangeText(pagination, current, totalRows))
                );
            }

            var buttonGroup = $('<div class="btn-group btn-group-sm" role="group" aria-label="Pagination"></div>');
            buttonGroup
                .append(createCompactPaginationButton(t('previous', 'Previous'), 'fas fa-chevron-left', current > 1, function() {
                    loadTableData(current - 1);
                }))
                .append(createCompactPaginationButton(t('next', 'Next'), 'fas fa-chevron-right', current < totalPages, function() {
                    loadTableData(current + 1);
                }));

            target.append(buttonGroup);
        }

        function createCompactPaginationButton(label, iconClass, enabled, callback) {
            var button = $('<button type="button" class="btn btn-outline-secondary"></button>')
                .attr('aria-label', label)
                .prop('disabled', !enabled);

            button.append($('<i aria-hidden="true"></i>').addClass(iconClass));

            if (enabled) {
                button.on('click', function(event) {
                    event.preventDefault();
                    event.stopPropagation();
                    callback();
                    return false;
                });
            }

            return button;
        }

        function getPaginationRangeText(pagination, current, totalRows) {
            var startRange = totalRows === 0 ? 0 : ((current - 1) * pagination.per_page) + 1;
            var endRange = totalRows === 0 ? 0 : Math.min(current * pagination.per_page, totalRows);

            return t('showing_range', 'Showing {start}-{end} of {total}', {
                start: startRange,
                end: endRange,
                total: totalRows
            });
        }

        function createPageItem(pageNumber, isActive) {
            var item = $('<li class="page-item"></li>');
            if (isActive) {
                item.addClass('active');
            }
            item.append(
                $('<a class="page-link" href="javascript:void(0)"></a>')
                    .text(pageNumber)
                    .on('click', function(event) {
                        event.preventDefault();
                        event.stopPropagation();
                        loadTableData(pageNumber);
                        return false;
                    })
            );
            return item;
        }

        // Helper functions for batched row rendering
        function escapeHtml(value) {
            var s = (value === null || typeof value === 'undefined') ? '' : String(value);
            return s
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function deriveWidthAttr(widthValue) {
            var w = String(widthValue || '').trim();
            if (!w) { return { style: '', className: '' }; }
            var lower = w.toLowerCase();
            var units = ['px','rem','em','%','vw','vh'];
            var isStyle = lower.indexOf('calc(') !== -1;
            if (!isStyle) {
                for (var i = 0; i < units.length; i++) { if (lower.endsWith(units[i])) { isStyle = true; break; } }
            }
            if (isStyle) { return { style: 'width: ' + escapeHtml(w) + ';', className: '' }; }
            return { style: '', className: escapeHtml(w) };
        }

        function deepClone(value) {
            try {
                return JSON.parse(JSON.stringify(value));
            } catch (e) {
                return value;
            }
        }

        function buildUrlWithParam(baseUrl, paramName, paramValue) {
            var href = typeof baseUrl === 'string' ? baseUrl : '';
            var trimmedHref = href.trim();
            if (!trimmedHref) {
                return '';
            }
            var nameSource = typeof paramName === 'string' ? paramName : '';
            var paramKey = nameSource.trim() || 'exampleinput';
            var valueString = (paramValue === null || typeof paramValue === 'undefined')
                ? ''
                : String(paramValue);
            var hashIndex = trimmedHref.indexOf('#');
            var hash = '';
            if (hashIndex !== -1) {
                hash = trimmedHref.slice(hashIndex);
                trimmedHref = trimmedHref.slice(0, hashIndex);
            }
            var separator = trimmedHref.indexOf('?') === -1 ? '?' : '&';
            return trimmedHref + separator + encodeURIComponent(paramKey) + '=' + encodeURIComponent(valueString) + hash;
        }

        function followUrl(url, target, rel) {
            var href = typeof url === 'string' ? url.trim() : '';
            if (!href) {
                return;
            }
            var resolvedTarget = typeof target === 'string' ? target.trim() : '';
            if (!resolvedTarget || resolvedTarget === '_self') {
                window.location.href = href;
                return;
            }
            var link = document.createElement('a');
            link.href = href;
            link.target = resolvedTarget;
            if (typeof rel === 'string' && rel.trim()) {
                link.rel = rel.trim();
            }
            link.style.display = 'none';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        function hasNestedTablesConfigured() {
            return Array.isArray(nestedTablesConfig) && nestedTablesConfig.length > 0;
        }

        function buildActionCellHtml(rowMeta) {
            var fragments = [];
            var duplicateActionClass = getStyleClass('duplicate_action_button_class', 'btn btn-sm btn-info');
            var viewActionClass = getStyleClass('view_action_button_class', 'btn btn-sm btn-secondary');
            var editActionClass = getStyleClass('edit_action_button_class', 'btn btn-sm btn-primary');
            var deleteActionClass = getStyleClass('delete_action_button_class', 'btn btn-sm btn-danger');

            function renderLinkButton(linkMeta) {
                if (!linkMeta || typeof linkMeta !== 'object') {
                    return;
                }

                var href = String(linkMeta.url || '').trim();
                if (!href.length) {
                    return;
                }
                var classSource = String(linkMeta.button_class || '').trim();
                var classParts = classSource.length ? classSource.split(/\s+/) : [];
                if (classParts.indexOf('btn') === -1) {
                    classParts.unshift('btn');
                }
                if (classParts.indexOf('btn-sm') === -1) {
                    classParts.push('btn-sm');
                }
                if (classParts.indexOf('fastcrud-action-button') === -1) {
                    classParts.push('fastcrud-action-button');
                }
                if (classParts.indexOf('fastcrud-link-btn') === -1) {
                    classParts.push('fastcrud-link-btn');
                }
                var labelRaw = typeof linkMeta.label === 'string' ? linkMeta.label : '';
                var labelText = labelRaw.trim();
                var options = linkMeta.options && typeof linkMeta.options === 'object'
                    ? Object.assign({}, linkMeta.options)
                    : {};
                var attrString = '';
                var hasTitleAttr = false;
                var hasAriaAttr = false;
                var hasRoleAttr = false;

                var optionClassRaw = '';
                if (Object.prototype.hasOwnProperty.call(options, 'class')) {
                    optionClassRaw = String(options['class'] || '').trim();
                    delete options['class'];
                }

                if (optionClassRaw) {
                    optionClassRaw.split(/\s+/).forEach(function(extra) {
                        if (!extra) { return; }
                        if (classParts.indexOf(extra) === -1) {
                            classParts.push(extra);
                        }
                    });
                }

                Object.keys(options).forEach(function(optionKey) {
                    if (!Object.prototype.hasOwnProperty.call(options, optionKey)) {
                        return;
                    }
                    var attrName = String(optionKey);
                    if (!/^[A-Za-z0-9_:-]+$/.test(attrName)) {
                        return;
                    }
                    var lowerName = attrName.toLowerCase();
                    if (lowerName === 'href' || lowerName === 'class') {
                        return;
                    }
                    if (lowerName === 'title') {
                        hasTitleAttr = true;
                    } else if (lowerName === 'aria-label') {
                        hasAriaAttr = true;
                    } else if (lowerName === 'role') {
                        hasRoleAttr = true;
                    }
                    var attrValue = options[optionKey];
                    if (attrValue === null || typeof attrValue === 'undefined') {
                        return;
                    }
                    attrString += ' ' + escapeHtml(attrName) + '="' + escapeHtml(String(attrValue)) + '"';
                });

                if (!hasAriaAttr) {
                    var ariaLabel = labelText ? labelText : t('open_link', 'Open link');
                    attrString += ' aria-label="' + escapeHtml(ariaLabel) + '"';
                }
                if (!hasTitleAttr && labelText) {
                    attrString += ' title="' + escapeHtml(labelText) + '"';
                }
                if (!hasRoleAttr) {
                    attrString += ' role="button"';
                }

                var iconClass = typeof linkMeta.icon === 'string' ? linkMeta.icon.trim() : '';
                var iconHtml = iconClass ? '<i class="fastcrud-link-icon ' + escapeHtml(iconClass) + '"></i>' : '';
                var contentHtml;
                if (iconHtml && labelText) {
                    contentHtml = iconHtml + '<span class="fastcrud-link-btn-text ms-1">' + escapeHtml(labelText) + '</span>';
                } else if (iconHtml) {
                    contentHtml = iconHtml;
                } else if (labelText) {
                    contentHtml = escapeHtml(labelText);
                } else {
                    contentHtml = '<span class="visually-hidden">' + escapeHtml(t('open_link', 'Open link')) + '</span>';
                }

                var classAttr = classParts.join(' ');
                fragments.push('<a href="' + escapeHtml(href) + '" class="' + escapeHtml(classAttr) + '"' + attrString + '>' + contentHtml + '</a>');
            }

            function renderMultiLinkButton(multiMeta) {
                if (!multiMeta || typeof multiMeta !== 'object') {
                    return;
                }

                var buttonMeta = multiMeta.button && typeof multiMeta.button === 'object'
                    ? multiMeta.button
                    : {};
                var multiItems = Array.isArray(multiMeta.items) ? multiMeta.items : [];
                if (!multiItems.length) {
                    return;
                }

                var dropdownItems = [];
                multiItems.forEach(function(item) {
                    if (!item || typeof item !== 'object') {
                        return;
                    }

                    var itemTypeRaw = typeof item.type === 'string' ? item.type : '';
                    var itemType = itemTypeRaw ? itemTypeRaw.trim().toLowerCase() : '';
                    if (!itemType) {
                        itemType = 'link';
                    }

                    if (itemType === 'divider') {
                        var dividerTitleRaw = typeof item.title === 'string' ? item.title : '';
                        var dividerTitle = dividerTitleRaw.trim();
                        var dividerHtml = '<li class="fastcrud-multi-link-divider-wrapper">'
                            + '<hr class="dropdown-divider fastcrud-multi-link-divider" role="separator">';
                        if (dividerTitle) {
                            dividerHtml += '<div class="dropdown-header fastcrud-multi-link-divider-title">'
                                + escapeHtml(dividerTitle)
                                + '</div>';
                        }
                        dividerHtml += '</li>';
                        dropdownItems.push(dividerHtml);
                        return;
                    }

                    if (itemType === 'duplicate') {
                        var duplicateLabelRaw = typeof item.label === 'string' ? item.label : '';
                        var duplicateLabel = duplicateLabelRaw.trim();
                        if (!duplicateLabel) {
                            duplicateLabel = t('duplicate', 'Duplicate');
                        }

                        var duplicateClassParts = ['dropdown-item', 'fastcrud-multi-link-item', 'fastcrud-duplicate-btn'];
                        var duplicateOptions = item.options && typeof item.options === 'object'
                            ? Object.assign({}, item.options)
                            : {};
                        var duplicateOptionClassRaw = '';
                        if (Object.prototype.hasOwnProperty.call(duplicateOptions, 'class')) {
                            duplicateOptionClassRaw = String(duplicateOptions['class'] || '').trim();
                            delete duplicateOptions['class'];
                        }
                        if (duplicateOptionClassRaw) {
                            duplicateOptionClassRaw.split(/\s+/).forEach(function(extra) {
                                if (!extra) { return; }
                                if (duplicateClassParts.indexOf(extra) === -1) {
                                    duplicateClassParts.push(extra);
                                }
                            });
                        }

                        var duplicateAttrString = '';
                        var duplicateHasTitle = false;
                        var duplicateHasAria = false;
                        var duplicateHasRole = false;

                        Object.keys(duplicateOptions).forEach(function(optionKey) {
                            if (!Object.prototype.hasOwnProperty.call(duplicateOptions, optionKey)) {
                                return;
                            }
                            var attrName = String(optionKey);
                            if (!/^[A-Za-z0-9_:-]+$/.test(attrName)) {
                                return;
                            }
                            var lowerName = attrName.toLowerCase();
                            if (lowerName === 'href' || lowerName === 'class') {
                                return;
                            }
                            if (lowerName === 'title') {
                                duplicateHasTitle = true;
                            } else if (lowerName === 'aria-label') {
                                duplicateHasAria = true;
                            } else if (lowerName === 'role') {
                                duplicateHasRole = true;
                            }
                            var attrValue = duplicateOptions[optionKey];
                            if (attrValue === null || typeof attrValue === 'undefined') {
                                return;
                            }
                            duplicateAttrString += ' ' + escapeHtml(attrName) + '="' + escapeHtml(String(attrValue)) + '"';
                        });

                        if (!duplicateHasAria) {
                            duplicateAttrString += ' aria-label="' + escapeHtml(duplicateLabel) + '"';
                        }
                        if (!duplicateHasTitle) {
                            duplicateAttrString += ' title="' + escapeHtml(duplicateLabel) + '"';
                        }
                        if (!duplicateHasRole) {
                            duplicateAttrString += ' role="menuitem"';
                        }

                        var uniqueDuplicateClassParts = [];
                        duplicateClassParts.forEach(function(part) {
                            if (!part) { return; }
                            if (uniqueDuplicateClassParts.indexOf(part) === -1) {
                                uniqueDuplicateClassParts.push(part);
                            }
                        });

                        var duplicateClassAttr = uniqueDuplicateClassParts.join(' ');
                        var duplicateIconClass = item.icon && typeof item.icon === 'string' ? item.icon.trim() : '';
                        var duplicateContent;
                        if (duplicateIconClass) {
                            var duplicateIconHtml = '<i class="fastcrud-multi-link-item-icon ' + escapeHtml(duplicateIconClass) + '"></i>';
                            duplicateContent = duplicateIconHtml + '<span class="fastcrud-multi-link-item-text ms-2">' + escapeHtml(duplicateLabel) + '</span>';
                        } else {
                            duplicateContent = escapeHtml(duplicateLabel);
                        }

                        dropdownItems.push('<li class="fastcrud-multi-link-item-wrapper" data-fastcrud-filter-text="' + escapeHtml(duplicateLabel) + '"><button type="button" class="' + escapeHtml(duplicateClassAttr) + '"' + duplicateAttrString + '>' + duplicateContent + '</button></li>');
                        return;
                    }

                    if (itemType === 'delete') {
                        var deleteLabelRaw = typeof item.label === 'string' ? item.label : '';
                        var deleteLabel = deleteLabelRaw.trim();
                        if (!deleteLabel) {
                            deleteLabel = t('delete', 'Delete');
                        }

                        var deleteClassParts = ['dropdown-item', 'fastcrud-multi-link-item', 'fastcrud-delete-btn'];
                        var deleteOptions = item.options && typeof item.options === 'object'
                            ? Object.assign({}, item.options)
                            : {};
                        var deleteOptionClassRaw = '';
                        if (Object.prototype.hasOwnProperty.call(deleteOptions, 'class')) {
                            deleteOptionClassRaw = String(deleteOptions['class'] || '').trim();
                            delete deleteOptions['class'];
                        }
                        if (deleteOptionClassRaw) {
                            deleteOptionClassRaw.split(/\s+/).forEach(function(extra) {
                                if (!extra) { return; }
                                if (deleteClassParts.indexOf(extra) === -1) {
                                    deleteClassParts.push(extra);
                                }
                            });
                        }

                        var deleteAttrString = '';
                        var deleteHasTitle = false;
                        var deleteHasAria = false;
                        var deleteHasRole = false;

                        Object.keys(deleteOptions).forEach(function(optionKey) {
                            if (!Object.prototype.hasOwnProperty.call(deleteOptions, optionKey)) {
                                return;
                            }
                            var attrName = String(optionKey);
                            if (!/^[A-Za-z0-9_:-]+$/.test(attrName)) {
                                return;
                            }
                            var lowerName = attrName.toLowerCase();
                            if (lowerName === 'href' || lowerName === 'class') {
                                return;
                            }
                            if (lowerName === 'title') {
                                deleteHasTitle = true;
                            } else if (lowerName === 'aria-label') {
                                deleteHasAria = true;
                            } else if (lowerName === 'role') {
                                deleteHasRole = true;
                            }
                            var attrValue = deleteOptions[optionKey];
                            if (attrValue === null || typeof attrValue === 'undefined') {
                                return;
                            }
                            deleteAttrString += ' ' + escapeHtml(attrName) + '="' + escapeHtml(String(attrValue)) + '"';
                        });

                        if (!deleteHasAria) {
                            deleteAttrString += ' aria-label="' + escapeHtml(deleteLabel) + '"';
                        }
                        if (!deleteHasTitle) {
                            deleteAttrString += ' title="' + escapeHtml(deleteLabel) + '"';
                        }
                        if (!deleteHasRole) {
                            deleteAttrString += ' role="menuitem"';
                        }

                        var uniqueDeleteClassParts = [];
                        deleteClassParts.forEach(function(part) {
                            if (!part) { return; }
                            if (uniqueDeleteClassParts.indexOf(part) === -1) {
                                uniqueDeleteClassParts.push(part);
                            }
                        });

                        var deleteClassAttr = uniqueDeleteClassParts.join(' ');
                        var deleteIconClass = item.icon && typeof item.icon === 'string' ? item.icon.trim() : '';
                        var deleteContent;
                        if (deleteIconClass) {
                            var deleteIconHtml = '<i class="fastcrud-multi-link-item-icon ' + escapeHtml(deleteIconClass) + '"></i>';
                            deleteContent = deleteIconHtml + '<span class="fastcrud-multi-link-item-text ms-2">' + escapeHtml(deleteLabel) + '</span>';
                        } else {
                            deleteContent = escapeHtml(deleteLabel);
                        }

                        dropdownItems.push('<li class="fastcrud-multi-link-item-wrapper" data-fastcrud-filter-text="' + escapeHtml(deleteLabel) + '"><button type="button" class="' + escapeHtml(deleteClassAttr) + '"' + deleteAttrString + '>' + deleteContent + '</button></li>');
                        return;
                    }

                    if (itemType === 'input') {
                        var inputHref = String(item.url || '').trim();
                        var inputLabelRaw = typeof item.label === 'string' ? item.label : '';
                        var inputLabel = inputLabelRaw.trim();
                        if (!inputHref || !inputLabel) {
                            return;
                        }

                        var inputNameRaw = typeof item.input_name === 'string' ? item.input_name : '';
                        var inputName = inputNameRaw.trim() || 'exampleinput';
                        var inputPromptRaw = typeof item.prompt === 'string' ? item.prompt : '';
                        var inputPrompt = inputPromptRaw.trim();

                        var inputClassParts = ['dropdown-item', 'fastcrud-multi-link-item', 'fastcrud-multi-link-input'];
                        var inputOptions = item.options && typeof item.options === 'object'
                            ? Object.assign({}, item.options)
                            : {};
                        var inputOptionClassRaw = '';
                        if (Object.prototype.hasOwnProperty.call(inputOptions, 'class')) {
                            inputOptionClassRaw = String(inputOptions['class'] || '').trim();
                            delete inputOptions['class'];
                        }
                        if (inputOptionClassRaw) {
                            inputOptionClassRaw.split(/\s+/).forEach(function(extra) {
                                if (!extra) { return; }
                                if (inputClassParts.indexOf(extra) === -1) {
                                    inputClassParts.push(extra);
                                }
                            });
                        }

                        var inputAttrString = '';
                        var inputHasTitle = false;
                        var inputHasAria = false;
                        var inputHasRole = false;
                        var inputTargetAttr = '';
                        var inputRelAttr = '';

                        Object.keys(inputOptions).forEach(function(optionKey) {
                            if (!Object.prototype.hasOwnProperty.call(inputOptions, optionKey)) {
                                return;
                            }
                            var attrName = String(optionKey);
                            if (!/^[A-Za-z0-9_:-]+$/.test(attrName)) {
                                return;
                            }
                            var lowerName = attrName.toLowerCase();
                            if (lowerName === 'class' || lowerName === 'href' || lowerName === 'type') {
                                return;
                            }
                            if (lowerName === 'target') {
                                inputTargetAttr = String(inputOptions[optionKey] || '').trim();
                                return;
                            }
                            if (lowerName === 'rel') {
                                inputRelAttr = String(inputOptions[optionKey] || '').trim();
                                return;
                            }
                            if (lowerName === 'title') {
                                inputHasTitle = true;
                            } else if (lowerName === 'aria-label') {
                                inputHasAria = true;
                            } else if (lowerName === 'role') {
                                inputHasRole = true;
                            }
                            var attrValue = inputOptions[optionKey];
                            if (attrValue === null || typeof attrValue === 'undefined') {
                                return;
                            }
                            inputAttrString += ' ' + escapeHtml(attrName) + '="' + escapeHtml(String(attrValue)) + '"';
                        });

                        if (!inputHasAria) {
                            inputAttrString += ' aria-label="' + escapeHtml(inputLabel) + '"';
                        }
                        if (!inputHasTitle) {
                            inputAttrString += ' title="' + escapeHtml(inputLabel) + '"';
                        }
                        if (!inputHasRole) {
                            inputAttrString += ' role="menuitem"';
                        }

                        inputAttrString += ' data-fastcrud-input-url="' + escapeHtml(inputHref) + '"';
                        inputAttrString += ' data-fastcrud-input-name="' + escapeHtml(inputName) + '"';
                        if (inputPrompt) {
                            inputAttrString += ' data-fastcrud-input-prompt="' + escapeHtml(inputPrompt) + '"';
                        }
                        if (inputTargetAttr) {
                            inputAttrString += ' data-fastcrud-input-target="' + escapeHtml(inputTargetAttr) + '"';
                        }
                        if (inputRelAttr) {
                            inputAttrString += ' data-fastcrud-input-rel="' + escapeHtml(inputRelAttr) + '"';
                        }

                        var uniqueInputClassParts = [];
                        inputClassParts.forEach(function(part) {
                            if (!part) { return; }
                            if (uniqueInputClassParts.indexOf(part) === -1) {
                                uniqueInputClassParts.push(part);
                            }
                        });

                        var inputClassAttr = uniqueInputClassParts.join(' ');
                        var inputIconClass = item.icon && typeof item.icon === 'string' ? item.icon.trim() : '';
                        var inputContent;
                        if (inputIconClass) {
                            var inputIconHtml = '<i class="fastcrud-multi-link-item-icon ' + escapeHtml(inputIconClass) + '"></i>';
                            inputContent = inputIconHtml + '<span class="fastcrud-multi-link-item-text ms-2">' + escapeHtml(inputLabel) + '</span>';
                        } else {
                            inputContent = escapeHtml(inputLabel);
                        }

                        dropdownItems.push('<li class="fastcrud-multi-link-item-wrapper" data-fastcrud-filter-text="' + escapeHtml(inputLabel) + '"><button type="button" class="' + escapeHtml(inputClassAttr) + '"' + inputAttrString + '>' + inputContent + '</button></li>');
                        return;
                    }

                    var itemHref = String(item.url || '').trim();
                    var itemLabelRaw = typeof item.label === 'string' ? item.label : '';
                    var itemLabel = itemLabelRaw.trim();
                    if (!itemHref || !itemLabel) {
                        return;
                    }

                    var itemClassParts = ['dropdown-item', 'fastcrud-multi-link-item'];
                    var itemOptions = item.options && typeof item.options === 'object'
                        ? Object.assign({}, item.options)
                        : {};
                    var itemOptionClassRaw = '';
                    if (Object.prototype.hasOwnProperty.call(itemOptions, 'class')) {
                        itemOptionClassRaw = String(itemOptions['class'] || '').trim();
                        delete itemOptions['class'];
                    }
                    if (itemOptionClassRaw) {
                        itemOptionClassRaw.split(/\s+/).forEach(function(extra) {
                            if (!extra) { return; }
                            if (itemClassParts.indexOf(extra) === -1) {
                                itemClassParts.push(extra);
                            }
                        });
                    }

                    var itemAttrString = '';
                    var itemHasTitle = false;
                    var itemHasAria = false;
                    var itemHasRole = false;

                    Object.keys(itemOptions).forEach(function(optionKey) {
                        if (!Object.prototype.hasOwnProperty.call(itemOptions, optionKey)) {
                            return;
                        }
                        var attrName = String(optionKey);
                        if (!/^[A-Za-z0-9_:-]+$/.test(attrName)) {
                            return;
                        }
                        var lowerName = attrName.toLowerCase();
                        if (lowerName === 'href' || lowerName === 'class') {
                            return;
                        }
                        if (lowerName === 'title') {
                            itemHasTitle = true;
                        } else if (lowerName === 'aria-label') {
                            itemHasAria = true;
                        } else if (lowerName === 'role') {
                            itemHasRole = true;
                        }
                        var attrValue = itemOptions[optionKey];
                        if (attrValue === null || typeof attrValue === 'undefined') {
                            return;
                        }
                        itemAttrString += ' ' + escapeHtml(attrName) + '="' + escapeHtml(String(attrValue)) + '"';
                    });

                    if (!itemHasAria) {
                        itemAttrString += ' aria-label="' + escapeHtml(itemLabel) + '"';
                    }
                    if (!itemHasTitle) {
                        itemAttrString += ' title="' + escapeHtml(itemLabel) + '"';
                    }
                    if (!itemHasRole) {
                        itemAttrString += ' role="menuitem"';
                    }

                    var uniqueItemClassParts = [];
                    itemClassParts.forEach(function(part) {
                        if (!part) { return; }
                        if (uniqueItemClassParts.indexOf(part) === -1) {
                            uniqueItemClassParts.push(part);
                        }
                    });

                    var itemClassAttr = uniqueItemClassParts.join(' ');
                    var itemIconClass = item.icon && typeof item.icon === 'string' ? item.icon.trim() : '';
                    var itemContent;
                    if (itemIconClass) {
                        var itemIconHtml = '<i class="fastcrud-multi-link-item-icon ' + escapeHtml(itemIconClass) + '"></i>';
                        itemContent = itemIconHtml + '<span class="fastcrud-multi-link-item-text ms-2">' + escapeHtml(itemLabel) + '</span>';
                    } else {
                        itemContent = escapeHtml(itemLabel);
                    }

                    dropdownItems.push('<li class="fastcrud-multi-link-item-wrapper" data-fastcrud-filter-text="' + escapeHtml(itemLabel) + '"><a href="' + escapeHtml(itemHref) + '" class="' + escapeHtml(itemClassAttr) + '"' + itemAttrString + '>' + itemContent + '</a></li>');
                });

                if (!dropdownItems.length) {
                    return;
                }

                var filterEnabled = !!buttonMeta.enable_filter;
                if (filterEnabled) {
                    var filterLabel = t('search', 'Search');
                    var filterPlaceholder = t('search_placeholder', 'Search...');
                    var filterHtml = '<li class="fastcrud-multi-link-filter-wrapper" role="none">'
                        + '<input type="search" class="form-control form-control-sm fastcrud-multi-link-filter-input"'
                        + ' aria-label="' + escapeHtml(filterLabel) + '"'
                        + ' placeholder="' + escapeHtml(filterPlaceholder) + '"'
                        + ' autocomplete="off">'
                        + '</li>';
                    dropdownItems.unshift(filterHtml);
                }

                var triggerClassSource = String(buttonMeta.button_class || '').trim();
                var triggerClassParts = triggerClassSource ? triggerClassSource.split(/\s+/) : [];
                if (triggerClassParts.indexOf('btn') === -1) { triggerClassParts.unshift('btn'); }
                var hasSizeClass = triggerClassParts.some(function(part) {
                    return /^btn-(?:sm|md|lg|xl)$/i.test(part);
                });
                if (!hasSizeClass) {
                    triggerClassParts.push('btn-sm');
                }
                if (triggerClassParts.indexOf('dropdown-toggle') === -1) { triggerClassParts.push('dropdown-toggle'); }
                if (triggerClassParts.indexOf('fastcrud-action-button') === -1) { triggerClassParts.push('fastcrud-action-button'); }
                if (triggerClassParts.indexOf('fastcrud-multi-link-trigger') === -1) { triggerClassParts.push('fastcrud-multi-link-trigger'); }

                var triggerOptions = buttonMeta.options && typeof buttonMeta.options === 'object'
                    ? Object.assign({}, buttonMeta.options)
                    : {};
                var triggerOptionClassRaw = '';
                if (Object.prototype.hasOwnProperty.call(triggerOptions, 'class')) {
                    triggerOptionClassRaw = String(triggerOptions['class'] || '').trim();
                    delete triggerOptions['class'];
                }
                if (triggerOptionClassRaw) {
                    triggerOptionClassRaw.split(/\s+/).forEach(function(extra) {
                        if (!extra) { return; }
                        if (triggerClassParts.indexOf(extra) === -1) {
                            triggerClassParts.push(extra);
                        }
                    });
                }

                var triggerAttrString = '';
                var triggerHasTitle = false;
                var triggerHasAria = false;
                var triggerHasRole = false;
                var triggerHasToggle = false;
                var triggerHasExpanded = false;
                var triggerHasHaspopup = false;

                Object.keys(triggerOptions).forEach(function(optionKey) {
                    if (!Object.prototype.hasOwnProperty.call(triggerOptions, optionKey)) {
                        return;
                    }
                    var attrName = String(optionKey);
                    if (!/^[A-Za-z0-9_:-]+$/.test(attrName)) {
                        return;
                    }
                    var lowerName = attrName.toLowerCase();
                    if (lowerName === 'class' || lowerName === 'href') {
                        return;
                    }
                    if (lowerName === 'title') {
                        triggerHasTitle = true;
                    } else if (lowerName === 'aria-label') {
                        triggerHasAria = true;
                    } else if (lowerName === 'role') {
                        triggerHasRole = true;
                    } else if (lowerName === 'data-bs-toggle') {
                        triggerHasToggle = true;
                    } else if (lowerName === 'aria-expanded') {
                        triggerHasExpanded = true;
                    } else if (lowerName === 'aria-haspopup') {
                        triggerHasHaspopup = true;
                    }
                    var attrValue = triggerOptions[optionKey];
                    if (attrValue === null || typeof attrValue === 'undefined') {
                        return;
                    }
                    triggerAttrString += ' ' + escapeHtml(attrName) + '="' + escapeHtml(String(attrValue)) + '"';
                });

                var uniqueTriggerClassParts = [];
                triggerClassParts.forEach(function(part) {
                    if (!part) {
                        return;
                    }
                    if (uniqueTriggerClassParts.indexOf(part) === -1) {
                        uniqueTriggerClassParts.push(part);
                    }
                });
                triggerClassParts = uniqueTriggerClassParts;

                var triggerLabel = typeof buttonMeta.label === 'string' ? buttonMeta.label.trim() : '';

                if (!triggerHasAria) {
                    var triggerAriaLabel = triggerLabel ? triggerLabel : 'Open menu';
                    triggerAttrString += ' aria-label="' + escapeHtml(triggerAriaLabel) + '"';
                }
                if (!triggerHasTitle && triggerLabel) {
                    triggerAttrString += ' title="' + escapeHtml(triggerLabel) + '"';
                }
                if (!triggerHasRole) {
                    triggerAttrString += ' role="button"';
                }
                if (!triggerHasToggle) {
                    triggerAttrString += ' data-bs-toggle="dropdown"';
                }
                if (!triggerHasExpanded) {
                    triggerAttrString += ' aria-expanded="false"';
                }
                if (!triggerHasHaspopup) {
                    triggerAttrString += ' aria-haspopup="true"';
                }

                var triggerClassAttr = triggerClassParts.join(' ');
                var triggerIconClass = typeof buttonMeta.icon === 'string' ? buttonMeta.icon.trim() : '';
                var triggerIconHtml = triggerIconClass
                    ? '<i class="fastcrud-multi-link-icon ' + escapeHtml(triggerIconClass) + '"></i>'
                    : '';
                var triggerContent;
                if (triggerIconHtml && triggerLabel) {
                    triggerContent = triggerIconHtml + '<span class="fastcrud-multi-link-text ms-1">' + escapeHtml(triggerLabel) + '</span>';
                } else if (triggerIconHtml) {
                    triggerContent = triggerIconHtml;
                } else if (triggerLabel) {
                    triggerContent = escapeHtml(triggerLabel);
                } else {
                    triggerContent = '<span class="visually-hidden">Open menu</span>';
                }

                var menuClassSource = String(buttonMeta.menu_class || '').trim();
                if (!menuClassSource) {
                    menuClassSource = 'dropdown-menu dropdown-menu-end';
                }
                var menuClassParts = menuClassSource ? menuClassSource.split(/\s+/) : [];
                if (menuClassParts.indexOf('dropdown-menu') === -1) {
                    menuClassParts.unshift('dropdown-menu');
                }
                if (menuClassParts.indexOf('fastcrud-multi-link-menu') === -1) {
                    menuClassParts.push('fastcrud-multi-link-menu');
                }
                if (filterEnabled && menuClassParts.indexOf('fastcrud-multi-link-filterable') === -1) {
                    menuClassParts.push('fastcrud-multi-link-filterable');
                }
                var uniqueMenuClassParts = [];
                menuClassParts.forEach(function(part) {
                    if (!part) {
                        return;
                    }
                    if (uniqueMenuClassParts.indexOf(part) === -1) {
                        uniqueMenuClassParts.push(part);
                    }
                });
                var menuClassAttr = uniqueMenuClassParts.join(' ');

                var containerClassSource = String(buttonMeta.container_class || '').trim();
                if (!containerClassSource) {
                    containerClassSource = 'btn-group';
                }
                var containerClassParts = containerClassSource ? containerClassSource.split(/\s+/) : [];
                if (containerClassParts.indexOf('fastcrud-action-button-group') === -1) {
                    containerClassParts.push('fastcrud-action-button-group');
                }
                if (containerClassParts.indexOf('fastcrud-multi-link-btn') === -1) {
                    containerClassParts.push('fastcrud-multi-link-btn');
                }
                var hasWrapperClass = containerClassParts.some(function(part) {
                    var lower = part.toLowerCase();
                    return lower === 'btn-group' || lower === 'dropdown' || lower === 'dropup' || lower === 'dropend' || lower === 'dropstart';
                });
                if (!hasWrapperClass) {
                    containerClassParts.unshift('btn-group');
                }
                var uniqueContainerClassParts = [];
                containerClassParts.forEach(function(part) {
                    if (!part) {
                        return;
                    }
                    if (uniqueContainerClassParts.indexOf(part) === -1) {
                        uniqueContainerClassParts.push(part);
                    }
                });
                var containerClassAttr = uniqueContainerClassParts.join(' ');
                var containerAttrString = buttonMeta.highlight_row_on_open
                    ? ' data-fastcrud-highlight-row-on-open="1"'
                    : '';

                var triggerHtml = '<button type="button" class="' + escapeHtml(triggerClassAttr) + '"' + triggerAttrString + '>' + triggerContent + '</button>';
                var menuHtml = '<ul class="' + escapeHtml(menuClassAttr) + '" role="menu">' + dropdownItems.join('') + '</ul>';

                fragments.push('<div class="' + escapeHtml(containerClassAttr) + '"' + containerAttrString + '>' + triggerHtml + menuHtml + '</div>');
            }

            var hasCustomButtonOrder = rowMeta
                && Array.isArray(rowMeta.action_button_order)
                && rowMeta.action_button_order.length;
            if (hasCustomButtonOrder) {
                var orderedLinkButtons = Array.isArray(rowMeta.link_buttons) ? rowMeta.link_buttons : [];
                var orderedMultiButtons = Array.isArray(rowMeta.multi_link_buttons) ? rowMeta.multi_link_buttons : [];
                var nextLinkIndex = 0;
                var nextMultiIndex = 0;

                rowMeta.action_button_order.forEach(function(orderEntry) {
                    if (!orderEntry || typeof orderEntry !== 'object') {
                        return;
                    }
                    var entryTypeRaw = typeof orderEntry.type === 'string' ? orderEntry.type : '';
                    var entryType = entryTypeRaw.trim().toLowerCase();
                    if (entryType !== 'link' && entryType !== 'multi') {
                        return;
                    }
                    var pointer = typeof orderEntry.index === 'number'
                        ? orderEntry.index
                        : (entryType === 'link' ? nextLinkIndex : nextMultiIndex);
                    if (entryType === 'link') {
                        if (pointer < 0 || pointer >= orderedLinkButtons.length) {
                            pointer = nextLinkIndex;
                        }
                        if (pointer >= 0 && pointer < orderedLinkButtons.length) {
                            renderLinkButton(orderedLinkButtons[pointer]);
                            nextLinkIndex = pointer + 1;
                        }
                        return;
                    }

                    if (pointer < 0 || pointer >= orderedMultiButtons.length) {
                        pointer = nextMultiIndex;
                    }
                    if (pointer >= 0 && pointer < orderedMultiButtons.length) {
                        renderMultiLinkButton(orderedMultiButtons[pointer]);
                        nextMultiIndex = pointer + 1;
                    }
                });

                for (; nextLinkIndex < orderedLinkButtons.length; nextLinkIndex++) {
                    renderLinkButton(orderedLinkButtons[nextLinkIndex]);
                }
                for (; nextMultiIndex < orderedMultiButtons.length; nextMultiIndex++) {
                    renderMultiLinkButton(orderedMultiButtons[nextMultiIndex]);
                }
            } else {
                if (rowMeta && Array.isArray(rowMeta.link_buttons)) {
                    rowMeta.link_buttons.forEach(renderLinkButton);
                }

                if (rowMeta && Array.isArray(rowMeta.multi_link_buttons)) {
                    rowMeta.multi_link_buttons.forEach(renderMultiLinkButton);
                }
            }

            if (duplicateEnabled) {
                var allowDuplicate = Object.prototype.hasOwnProperty.call(rowMeta, 'duplicate_allowed')
                    ? !!rowMeta.duplicate_allowed
                    : true;

                if (allowDuplicate) {
                    // Place duplicate button to the left of other action buttons
                    var duplicateClassAttr = (duplicateActionClass + ' fastcrud-action-button fastcrud-duplicate-btn').trim();
                    fragments.push('<button type="button" class="' + escapeHtml(duplicateClassAttr) + '" title="' + escapeHtml(t('duplicate', 'Duplicate')) + '" aria-label="' + escapeHtml(t('duplicate_record', 'Duplicate record')) + '">' + actionIcons.duplicate + '</button>');
                }
            }

            if (viewEnabled) {
                var allowView = Object.prototype.hasOwnProperty.call(rowMeta, 'view_allowed')
                    ? !!rowMeta.view_allowed
                    : true;

                if (allowView) {
                    var viewClassAttr = (viewActionClass + ' fastcrud-action-button fastcrud-view-btn').trim();
                    fragments.push('<button type="button" class="' + escapeHtml(viewClassAttr) + '" title="' + escapeHtml(t('view', 'View')) + '" aria-label="' + escapeHtml(t('view_record', 'View record')) + '">' + actionIcons.view + '</button>');
                }
            }

            if (editEnabled) {
                var allowEdit = Object.prototype.hasOwnProperty.call(rowMeta, 'edit_allowed')
                    ? !!rowMeta.edit_allowed
                    : true;

                if (allowEdit) {
                    var editClassAttr = (editActionClass + ' fastcrud-action-button fastcrud-edit-btn').trim();
                    fragments.push('<button type="button" class="' + escapeHtml(editClassAttr) + '" title="' + escapeHtml(t('edit', 'Edit')) + '" aria-label="' + escapeHtml(t('edit_record', 'Edit record')) + '">' + actionIcons.edit + '</button>');
                }
            }

            if (deleteEnabled) {
                var allowDelete = Object.prototype.hasOwnProperty.call(rowMeta, 'delete_allowed')
                    ? !!rowMeta.delete_allowed
                    : true;

                if (allowDelete) {
                    var deleteClassAttr = (deleteActionClass + ' fastcrud-action-button fastcrud-delete-btn').trim();
                    fragments.push('<button type="button" class="' + escapeHtml(deleteClassAttr) + '" title="' + escapeHtml(t('delete', 'Delete')) + '" aria-label="' + escapeHtml(t('delete_record', 'Delete record')) + '">' + actionIcons.delete + '</button>');
                }
            }

            var buttonsHtml = fragments.join('');
            return '<td class="text-end fastcrud-actions-cell"><div class="fastcrud-actions-stack">' + buttonsHtml + '</div></td>';
        }

        function isRowOrderingActive() {
            return !!(rowOrderingConfig && rowOrderingConfig.enabled && rowOrderingConfig.column);
        }

        function clearRowOrderingDragState() {
            table.find('tbody tr.fastcrud-row-order-dragging').removeClass('fastcrud-row-order-dragging');
            table.find('tbody tr.fastcrud-row-order-chosen').removeClass('fastcrud-row-order-chosen');
            table.find('tbody tr.fastcrud-row-order-ghost').removeClass('fastcrud-row-order-ghost');
        }

        function destroyRowOrderingSortable() {
            if (!rowOrderingSortable) {
                return;
            }
            try {
                rowOrderingSortable.destroy();
            } catch (error) {
            }
            rowOrderingSortable = null;
        }

        function setRowOrderingSortableDisabled(disabled) {
            if (!rowOrderingSortable || typeof rowOrderingSortable.option !== 'function') {
                return;
            }
            try {
                rowOrderingSortable.option('disabled', !!disabled);
            } catch (error) {
            }
        }

        function collectVisibleRowOrder() {
            var values = [];
            table.find('tbody tr').not('.fastcrud-nested-row').each(function() {
                var row = $(this);
                var value = row.attr('data-fastcrud-pk-value');
                if (typeof value !== 'undefined' && value !== '') {
                    values.push(value);
                }
            });
            return values;
        }

        function getRowOrderingStartPosition() {
            var start = 1;
            if (currentPagination && typeof currentPagination === 'object') {
                var pageValue = parseInt(currentPagination.current_page, 10);
                var perPageValue = parseInt(currentPagination.per_page, 10);
                if (!isNaN(pageValue) && pageValue > 0 && !isNaN(perPageValue) && perPageValue > 0) {
                    start = ((pageValue - 1) * perPageValue) + 1;
                }
            }
            return start;
        }

        function persistVisibleRowOrder() {
            if (!isRowOrderingActive() || rowOrderingSaving) {
                return;
            }

            var values = collectVisibleRowOrder();
            if (!values.length || !primaryKeyColumn) {
                return;
            }

            rowOrderingSaving = true;
            setRowOrderingSortableDisabled(true);

            var payload = {
                fastcrud_ajax: '1',
                action: 'row_order',
                table: tableName,
                id: tableId,
                primary_key_column: primaryKeyColumn,
                primary_key_values: values,
                position_start: getRowOrderingStartPosition()
            };
            attachCrudConfig(payload, false);

            $.ajax({
                url: window.location.pathname,
                type: 'POST',
                dataType: 'json',
                data: payload,
                success: function(response) {
                    if (response && response.success) {
                        loadTableData(currentPage);
                    } else {
                        showError(response && response.error ? response.error : 'Failed to reorder rows.');
                        loadTableData(currentPage);
                    }
                },
                error: function(_, __, error) {
                    showError('Failed to reorder rows. ' + error);
                    loadTableData(currentPage);
                },
                complete: function() {
                    rowOrderingSaving = false;
                    setRowOrderingSortableDisabled(false);
                }
            });
        }

        function ensureRowOrderingHandlers() {
            if (!isRowOrderingActive()) {
                destroyRowOrderingSortable();
                return;
            }

            var tbody = table.children('tbody').get(0);
            if (!tbody || !table.find('tbody .fastcrud-row-order-handle').length) {
                return;
            }

            if (rowOrderingSortable) {
                setRowOrderingSortableDisabled(rowOrderingSaving);
                return;
            }

            withSortableAssets(function() {
                var sortableBody = table.children('tbody').get(0);
                if (!isRowOrderingActive() || !sortableBody || rowOrderingSortable) {
                    return;
                }
                if (typeof window.Sortable === 'undefined' || typeof window.Sortable.create !== 'function') {
                    return;
                }

                rowOrderingSortable = window.Sortable.create(sortableBody, {
                    animation: 150,
                    handle: '.fastcrud-row-order-handle',
                    draggable: 'tr:not(.fastcrud-nested-row)',
                    ghostClass: 'fastcrud-row-order-ghost',
                    chosenClass: 'fastcrud-row-order-chosen',
                    dragClass: 'fastcrud-row-order-dragging',
                    disabled: rowOrderingSaving,
                    onStart: function() {
                        table.find('tbody tr.fastcrud-nested-row').remove();
                        nestedRowStates = {};
                    },
                    onEnd: function(event) {
                        clearRowOrderingDragState();
                        if (event && event.oldIndex !== event.newIndex) {
                            persistVisibleRowOrder();
                        }
                    }
                });
            });
        }

        function releaseTableRows(tbody) {
            tbody.find('table[data-table]').each(function() {
                var nested = (window.FastCrudTables || {})[this.id];
                if (nested && nested.destroy) { nested.destroy(); }
            });
            if (window.bootstrap && bootstrap.Tooltip) {
                tbody.find('[data-bs-toggle="tooltip"]').each(function() {
                    var tooltip = bootstrap.Tooltip.getInstance(this);
                    if (tooltip) { tooltip.dispose(); }
                });
            }
        }

        function populateTableRows(rows, pagination) {
            var tbody = table.children('tbody');
            var totalColumns = table.children('thead').find('th').length || 1;

            rows = Array.isArray(rows) ? rows : [];

            releaseTableRows(tbody);
            if (!rows || rows.length === 0) {
                tbody.html('');
                showEmptyRow(totalColumns, t('no_records', 'No records found.'));
                refreshSelectAllState();
                updateBatchDeleteButtonState();
                scheduleSidePanelHeightSync();
                return;
            }

            var html = '';
            var expandQueue = [];
            var hasNested = hasNestedTablesConfigured();
            var paginationInfo = (pagination && typeof pagination === 'object') ? pagination : null;
            var numbersPerPage = perPage;
            if (paginationInfo && typeof paginationInfo.per_page === 'number') {
                numbersPerPage = paginationInfo.per_page;
            }
            if (!numbersPerPage || numbersPerPage < 1) {
                numbersPerPage = rows.length;
            }
            var numbersPage = currentPage;
            if (paginationInfo && typeof paginationInfo.current_page === 'number' && paginationInfo.current_page > 0) {
                numbersPage = paginationInfo.current_page;
            }
            if (!numbersPage || numbersPage < 1) {
                numbersPage = 1;
            }
            var numberOffset = numbersEnabled ? Math.max(0, (numbersPage - 1) * numbersPerPage) : 0;

            $.each(rows, function(rowIndex, row) {
                var rowMeta = row.__fastcrud || {};
                var cellsMeta = rowMeta.cells || {};
                var rawValues = rowMeta.raw || {};
                var rowPrimaryKeyColumn = row.__fastcrud_primary_key || rowMeta.primary_key || primaryKeyColumn;
                var primaryValue;
                if (Object.prototype.hasOwnProperty.call(row, '__fastcrud_primary_value') && typeof row.__fastcrud_primary_value !== 'undefined') {
                    primaryValue = row.__fastcrud_primary_value;
                } else if (rowPrimaryKeyColumn) {
                    primaryValue = row[rowPrimaryKeyColumn];
                } else {
                    primaryValue = null;
                }

                var rowData = $.extend({}, row);
                delete rowData.__fastcrud;
                if (rowData.__fastcrud_raw) { delete rowData.__fastcrud_raw; }
                if (rawValues && typeof rawValues === 'object') {
                    Object.keys(rawValues).forEach(function(key) {
                        var rawValue = rawValues[key];
                        if (typeof rawValue !== 'undefined') {
                            rowData[key] = rawValue;
                        }
                    });
                }

                var rowKey = null;
                var primaryValueString = (typeof primaryValue === 'undefined' || primaryValue === null)
                    ? ''
                    : String(primaryValue);
                if (rowPrimaryKeyColumn && primaryValueString !== '') {
                    try {
                        rowKey = rowCacheKey(rowPrimaryKeyColumn, primaryValueString);
                        rowCache[rowKey] = rowData;
                    } catch (e) {}
                }

                var cells = '';
                var rowDeleteAllowed = deleteEnabled;
                if (rowDeleteAllowed && Object.prototype.hasOwnProperty.call(rowMeta, 'delete_allowed')) {
                    rowDeleteAllowed = !!rowMeta.delete_allowed;
                }

                var rowEditAllowed = editEnabled;
                if (rowEditAllowed && Object.prototype.hasOwnProperty.call(rowMeta, 'edit_allowed')) {
                    rowEditAllowed = !!rowMeta.edit_allowed;
                }

                if (rowOrderingConfig && rowOrderingConfig.enabled) {
                    var orderable = rowPrimaryKeyColumn && primaryValueString !== '' && rowEditAllowed;
                    if (orderable) {
                        cells += '<td class="text-center fastcrud-row-order-cell"><button type="button" class="btn btn-sm btn-link text-muted fastcrud-row-order-handle" aria-label="Drag to reorder row" title="Drag to reorder row" data-fastcrud-pk="' + escapeHtml(String(rowPrimaryKeyColumn)) + '" data-fastcrud-pk-value="' + escapeHtml(primaryValueString) + '"><i class="fas fa-grip-vertical" aria-hidden="true"></i></button></td>';
                    } else {
                        cells += '<td class="text-center fastcrud-row-order-cell text-muted"></td>';
                    }
                }

                if (hasNested) {
                    var isExpanded = rowKey && nestedRowStates[rowKey];
                    if (isExpanded && rowKey) {
                        expandQueue.push({
                            key: rowKey,
                            pk: {
                                column: rowPrimaryKeyColumn,
                                value: primaryValueString
                            }
                        });
                    }

                    var ariaLabel = isExpanded ? 'Collapse nested content' : 'Expand nested content';
                    var nestedToggleBaseClass = getStyleClass('nested_toggle_button_classes', 'btn btn-link p-0');
                    var toggleClassValue = 'fastcrud-nested-toggle';
                    if (nestedToggleBaseClass) {
                        toggleClassValue += ' ' + nestedToggleBaseClass;
                    }
                    var toggleAttrs = [
                        'type="button"',
                        'class="' + escapeHtml(toggleClassValue) + '"',
                        'aria-expanded="' + (isExpanded ? 'true' : 'false') + '"',
                        'aria-label="' + escapeHtml(ariaLabel) + '"',
                        'data-fastcrud-expanded="' + (isExpanded ? 'true' : 'false') + '"'
                    ];
                    if (rowKey) {
                        toggleAttrs.push('data-fastcrud-row-key="' + escapeHtml(rowKey) + '"');
                    }
                    if (rowPrimaryKeyColumn) {
                        toggleAttrs.push('data-fastcrud-pk="' + escapeHtml(String(rowPrimaryKeyColumn)) + '"');
                    }
                    if (primaryValueString !== '') {
                        toggleAttrs.push('data-fastcrud-pk-value="' + escapeHtml(primaryValueString) + '"');
                    }
                    var iconHtml = isExpanded ? actionIcons.collapse : actionIcons.expand;
                    cells += '<td class="fastcrud-nested-cell"><button ' + toggleAttrs.join(' ') + '>' + iconHtml + '</button></td>';
                }

                if (batchDeleteEnabled) {
                    var checkboxAttrs = ['type="checkbox"', 'class="form-check-input fastcrud-select-row"'];
                    var selectable = rowPrimaryKeyColumn && primaryValueString !== '';

                    if (selectable) {
                        var selectionAllowed = false;
                        if (allowBatchDeleteButton) {
                            selectionAllowed = rowDeleteAllowed;
                        }

                        if (!selectionAllowed && Array.isArray(bulkActions) && bulkActions.length) {
                            selectionAllowed = rowEditAllowed || rowDeleteAllowed;
                        }

                        selectable = selectionAllowed;
                    }

                    if (selectable) {
                        var selectKey = selectionKey(rowPrimaryKeyColumn, primaryValueString);
                        checkboxAttrs.push('data-fastcrud-key="' + escapeHtml(selectKey) + '"');
                        checkboxAttrs.push('data-fastcrud-pk="' + escapeHtml(String(rowPrimaryKeyColumn)) + '"');
                        checkboxAttrs.push('data-fastcrud-pk-value="' + escapeHtml(primaryValueString) + '"');
                        if (isSelected(rowPrimaryKeyColumn, primaryValueString)) {
                            checkboxAttrs.push('checked');
                        }
                    } else {
                        checkboxAttrs.push('disabled');
                        setSelection(rowPrimaryKeyColumn, primaryValueString, false);
                    }

                    cells += '<td class="text-center fastcrud-select-cell"><input ' + checkboxAttrs.join(' ') + '></td>';
                }

                if (numbersEnabled) {
                    var numericIndex = typeof rowIndex === 'number' ? rowIndex : parseInt(rowIndex, 10);
                    if (isNaN(numericIndex)) {
                        numericIndex = 0;
                    }
                    var rowNumber = numberOffset + numericIndex + 1;
                    cells += '<td class="text-center fastcrud-number-cell">' + escapeHtml(String(rowNumber)) + '</td>';
                }

                $.each(columnsCache, function(colIndex, column) {
                    var cellMeta = cellsMeta[column] || {};
                    var displayValue;
                    if (typeof cellMeta.display !== 'undefined') {
                        displayValue = cellMeta.display;
                    } else {
                        var rawValue = row[column];
                        displayValue = (rawValue === null || typeof rawValue === 'undefined') ? '' : rawValue;
                    }

                    var cls = cellMeta.class ? String(cellMeta.class) : (columnClasses[column] || '');
                    var widthValue = (cellMeta.width || columnWidths[column]);
                    var widthAttr = deriveWidthAttr(widthValue);
                    var classParts = [];
                    if (cls) { classParts.push(escapeHtml(cls)); }
                    if (widthAttr.className) { classParts.push(widthAttr.className); }
                    try {
                        var baseKey = String(column).indexOf('__') !== -1 ? String(column).split('__').pop() : String(column);
                        var inlineFieldKey = inlineEditFields[String(column)] ? String(column) : String(baseKey);
                        if ((inlineEditFields[String(column)] || inlineEditFields[String(baseKey)])
                            && isFieldVisibleForRow(inlineFieldKey, 'edit', row)
                            && isFieldEditableForRow(inlineFieldKey, 'edit', row)) {
                            classParts.push('fastcrud-inline-cell');
                        }
                    } catch (e) {}
                    var classAttr = classParts.length ? (' class="' + classParts.join(' ') + '"') : '';
                    var styleEnhance = '';
                    try {
                        var baseKey2 = String(column).indexOf('__') !== -1 ? String(column).split('__').pop() : String(column);
                        var inlineFieldKey2 = inlineEditFields[String(column)] ? String(column) : String(baseKey2);
                        if ((inlineEditFields[String(column)] || inlineEditFields[String(baseKey2)])
                            && isFieldVisibleForRow(inlineFieldKey2, 'edit', row)
                            && isFieldEditableForRow(inlineFieldKey2, 'edit', row)) {
                            styleEnhance = 'cursor: text;';
                        }
                    } catch (e) {}
                    var styleAttr = (widthAttr.style || styleEnhance) ? (' style="' + (widthAttr.style ? widthAttr.style + (styleEnhance ? ' ' + styleEnhance : '') : styleEnhance) + '"') : '';

                    var attrs = '';
                    if (cellMeta.tooltip) {
                        attrs += ' title="' + escapeHtml(cellMeta.tooltip) + '" data-bs-toggle="tooltip"';
                    }
                    if (cellMeta.attributes && typeof cellMeta.attributes === 'object') {
                        $.each(cellMeta.attributes, function(attrKey, attrValue) {
                            var k = String(attrKey);
                            var v = (attrValue === null || typeof attrValue === 'undefined') ? '' : String(attrValue);
                            attrs += ' ' + escapeHtml(k) + '="' + escapeHtml(v) + '"';
                        });
                    }

                    var inner = '';
                    if (cellMeta.html) {
                        inner = String(cellMeta.html);
                    } else {
                        inner = escapeHtml(displayValue);
                    }

                    cells += '<td data-fastcrud-column="' + escapeHtml(String(column)) + '"' + classAttr + styleAttr + attrs + '>' + inner + '</td>';
                });

                cells += buildActionCellHtml(rowMeta);

                var trAttrList = [];
                if (rowMeta.row_class) {
                    trAttrList.push('class="' + escapeHtml(String(rowMeta.row_class)) + '"');
                }
                if (rowPrimaryKeyColumn) {
                    trAttrList.push('data-fastcrud-pk="' + escapeHtml(String(rowPrimaryKeyColumn)) + '"');
                }
                if (primaryValueString !== '') {
                    trAttrList.push('data-fastcrud-pk-value="' + escapeHtml(primaryValueString) + '"');
                }
                if (rowKey) {
                    trAttrList.push('data-fastcrud-row-key="' + escapeHtml(rowKey) + '"');
                }

                var trAttrString = trAttrList.length ? (' ' + trAttrList.join(' ')) : '';
                html += '<tr' + trAttrString + '>' + cells + '</tr>';
            });

            tbody.html(html);
            refreshSelectAllState();
            updateBatchDeleteButtonState();
            ensureRowOrderingHandlers();
            scheduleSidePanelHeightSync();

            if (hasNested && expandQueue.length) {
                expandQueue.forEach(function(entry) {
                    if (!entry || !entry.key) {
                        return;
                    }

                    var targetRow = tbody.find('tr').filter(function() {
                        return $(this).attr('data-fastcrud-row-key') === entry.key;
                    }).first();

                    if (!targetRow.length) {
                        return;
                    }

                    var toggleButton = targetRow.find('.fastcrud-nested-toggle').first();
                    if (!toggleButton.length) {
                        return;
                    }

                    toggleNested(toggleButton, true, entry.pk);
                });
            }
        }

        function resolveNestedParentValue(config, rowData, pkInfo) {
            if (!config) {
                return null;
            }

            var candidates = [];
            if (config.parent_column && typeof config.parent_column === 'string') {
                candidates.push(String(config.parent_column));
            }
            if (config.parent_column_raw && typeof config.parent_column_raw === 'string') {
                candidates.push(String(config.parent_column_raw));
            }

            var value = null;
            for (var index = 0; index < candidates.length; index++) {
                var key = candidates[index];
                if (!key) {
                    continue;
                }

                if (Object.prototype.hasOwnProperty.call(rowData, key)) {
                    value = rowData[key];
                    break;
                }

                if (key.indexOf('.') !== -1) {
                    var tail = key.split('.').pop();
                    if (tail && Object.prototype.hasOwnProperty.call(rowData, tail)) {
                        value = rowData[tail];
                        break;
                    }
                }

                if (key.indexOf('__') !== -1) {
                    var denormalized = key.split('__').join('.');
                    if (Object.prototype.hasOwnProperty.call(rowData, denormalized)) {
                        value = rowData[denormalized];
                        break;
                    }
                }
            }

            if ((value === null || typeof value === 'undefined') && pkInfo && pkInfo.column) {
                if (candidates.indexOf(pkInfo.column) !== -1) {
                    value = pkInfo.value;
                }
            }

            return typeof value === 'undefined' ? null : value;
        }

        function requestNestedTable(target, config, rowData, pkInfo) {
            var container = target;
            var parentValue = resolveNestedParentValue(config, rowData, pkInfo);

            var payload = {
                fastcrud_ajax: '1',
                action: 'nested_fetch',
                table: config.table,
                parent_column: config.parent_column_raw || config.parent_column || '',
                foreign_column: config.foreign_column
            };
            attachNestedCrudConfig(payload, config);

            if (tableId) {
                payload.id = tableId + '--nested--' + (config.name || config.table || 'nested');
            }

            if (!payload.parent_column) {
                container.html('<div class="text-muted">Nested table is missing a parent column definition.</div>');
                return;
            }

            if (parentValue === null || typeof parentValue === 'undefined') {
                payload.parent_value = '__FASTCRUD_NULL__';
            } else {
                payload.parent_value = parentValue;
            }

            $.ajax({
                url: window.location.pathname,
                type: 'POST',
                dataType: 'json',
                data: payload,
                success: function(response) {
                    if (response && response.success && response.html) {
                        container.html(response.html);
                    } else {
                        var message = response && response.error ? response.error : t('no_records', 'No records found.');
                        container.html('<div class="text-muted">' + escapeHtml(String(message)) + '</div>');
                    }
                    scheduleSidePanelHeightSync();
                },
                error: function(_, __, error) {
                    container.html('<div class="alert alert-danger mb-0">' + escapeHtml(error || 'Failed to load nested records.') + '</div>');
                    scheduleSidePanelHeightSync();
                }
            });
        }

        function renderNestedSections(wrapper, configs, rowData, pkInfo, rowKey) {
            if (!Array.isArray(configs) || !configs.length) {
                wrapper.append('<div class="text-muted">No nested tables configured.</div>');
                return;
            }

            configs.forEach(function(config) {
                if (!config || typeof config !== 'object') {
                    return;
                }

                var title = (config.label && String(config.label).trim())
                    ? String(config.label).trim()
                    : makeLabel(config.table || 'Nested');

                var section = $('<div class="fastcrud-nested-section"></div>');
                var heading = $('<div class="d-flex justify-content-between align-items-center mb-2"></div>');
                heading.append($('<h6 class="mb-0"></h6>').text(title));
                section.append(heading);

                var body = $('<div class="fastcrud-nested-body border rounded p-3 bg-body"></div>');
                var placeholder = $('<div class="d-flex align-items-center gap-2 text-muted"></div>');
                var nestedLoadingText = t('loading_nested', 'Loading {title}...', { title: title });
                placeholder.append($('<div class="spinner-border spinner-border-sm" role="status"></div>').append($('<span class="visually-hidden"></span>').text(t('loading', 'Loading...'))));
                placeholder.append($('<span></span>').text(nestedLoadingText));
                body.append(placeholder);
                section.append(body);
                wrapper.append(section);

                requestNestedTable(body, config, rowData, pkInfo);
            });
        }

        function collapseNestedRow(button, rowKey) {
            var buttonEl = button && button.jquery ? button : $(button);
            var parentRow = buttonEl.closest('tr');
            var nestedRow = parentRow.next('.fastcrud-nested-row');
            if (nestedRow.length) {
                nestedRow.remove();
                scheduleSidePanelHeightSync();
            }

            buttonEl.attr('data-fastcrud-expanded', 'false').attr('aria-expanded', 'false').html(actionIcons.expand);
            if (rowKey && Object.prototype.hasOwnProperty.call(nestedRowStates, rowKey)) {
                delete nestedRowStates[rowKey];
            }
        }

        function expandNestedRow(button, rowKey, pkInfo) {
            var buttonEl = button && button.jquery ? button : $(button);
            var parentRow = buttonEl.closest('tr');
            if (!parentRow.length) {
                return;
            }

            var nestedRow = parentRow.next('.fastcrud-nested-row');
            var wrapper;
            if (!nestedRow.length) {
                var colspan = table.children('thead').find('th').length || 1;
                nestedRow = $('<tr class="fastcrud-nested-row"></tr>');
                var nestedCell = $('<td class="fastcrud-nested-cell-container"></td>').attr('colspan', colspan);
                wrapper = $('<div class="fastcrud-nested-wrapper"></div>');
                nestedCell.append(wrapper);
                nestedRow.append(nestedCell);
                parentRow.after(nestedRow);
                scheduleSidePanelHeightSync();
            } else {
                wrapper = nestedRow.find('.fastcrud-nested-wrapper').first();
                wrapper.empty();
                scheduleSidePanelHeightSync();
            }

            if (rowKey) {
                nestedRowStates[rowKey] = true;
            }

            buttonEl.attr('data-fastcrud-expanded', 'true').attr('aria-expanded', 'true').html(actionIcons.collapse);

            var loadingNotice = $('<div class="d-flex align-items-center gap-2 text-muted"></div>');
            loadingNotice.append($('<div class="spinner-border spinner-border-sm" role="status"></div>').append($('<span class="visually-hidden"></span>').text(t('loading', 'Loading...'))));
            loadingNotice.append($('<span></span>').text(t('fetching_nested_records', 'Fetching nested records...')));
            wrapper.append(loadingNotice);

            fetchRowByPk(pkInfo.column, pkInfo.value, 'edit').then(function(rowData) {
                wrapper.empty();
                renderNestedSections(wrapper, nestedTablesConfig, rowData, pkInfo, rowKey);
            }).catch(function(error) {
                wrapper.empty().append(
                    $('<div class="alert alert-danger mb-0"></div>').text((error && error.message) ? error.message : 'Failed to load nested records.')
                );
            });
        }

        function toggleNested(button, forceOpen, pkOverride) {
            if (!hasNestedTablesConfigured()) {
                return;
            }

            var buttonEl = button && button.jquery ? button : $(button);
            if (!buttonEl.length) {
                return;
            }

            var expanded = buttonEl.attr('data-fastcrud-expanded') === 'true';
            var pkInfo = pkOverride || getPkInfoFromElement(buttonEl);
            if (!pkInfo) {
                return;
            }

            if (typeof pkInfo.value !== 'undefined' && pkInfo.value !== null) {
                pkInfo.value = String(pkInfo.value);
            }

            var rowKey = buttonEl.attr('data-fastcrud-row-key') || null;
            if (!rowKey && typeof pkInfo.value !== 'undefined' && pkInfo.value !== null) {
                try {
                    rowKey = rowCacheKey(pkInfo.column, pkInfo.value);
                    buttonEl.attr('data-fastcrud-row-key', rowKey);
                } catch (e) {
                    rowKey = null;
                }
            }

            if (forceOpen) {
                expandNestedRow(buttonEl, rowKey, pkInfo);
                return;
            }

            if (expanded) {
                collapseNestedRow(buttonEl, rowKey);
            } else {
                expandNestedRow(buttonEl, rowKey, pkInfo);
            }
        }

        function loadTableData(page) {
            if (disposed) { return null; }
            var sequence = ++fetchSequence;
            currentPage = page || 1;
            rowCache = {};
            clearSelection();

            if (activeFetchRequest && typeof activeFetchRequest.abort === 'function') {
                activeFetchRequest.abort();
            }

            var tbody = table.children('tbody');
            var totalColumns = table.children('thead').find('th').length || 1;
            var loadingMessage = t('loading_data', 'Loading data...');

            if (!tableHasRendered) {
                showLoadingRow(totalColumns, loadingMessage);
            }

            syncQueryBuilderToConfig();

            var payload = {
                fastcrud_ajax: '1',
                action: 'fetch',
                table: tableName,
                id: tableId,
                page: currentPage,
                per_page: perPage > 0 ? perPage : 0,
                search_term: currentSearchTerm
            };
            if (typeof currentSearchColumn !== 'undefined' && currentSearchColumn !== null && String(currentSearchColumn).length) {
                payload.search_column = currentSearchColumn;
            }
            if (metadataHash) { payload.meta_hash = metadataHash; }
            attachCrudConfig(payload, true);

            var request = $.ajax({
                url: window.location.pathname,
                type: 'POST',
                dataType: 'json',
                data: payload,
                success: function(response) {
                    if (disposed || sequence !== fetchSequence) { return; }
                    if (response && response.success) {
                        if (response.meta !== null) {
                            applyMeta(response.meta || {});
                        }
                        if (Array.isArray(response.summaries)) {
                            metaConfig.summaries = response.summaries;
                        }
                        metadataHash = response.meta_hash || null;

                        if (Array.isArray(response.columns) && response.columns.length) {
                            columnsCache = response.columns;
                        }
                        if (!primaryKeyColumn) {
                            primaryKeyColumn = findPrimaryKey(columnsCache);
                        }

                        currentPagination = response.pagination || null;
                        populateTableRows(response.data || [], currentPagination);
                        refreshTooltips();
                        renderSummaries(metaConfig.summaries || []);

                        if (response.pagination) {
                            buildPagination(response.pagination);
                        }

                        tableHasRendered = true;
                        scheduleSidePanelHeightSync();
                    } else {
                        var errorMessage = response && response.error ? response.error : 'Failed to load data';
                        showError('Error: ' + errorMessage);
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    if (disposed || sequence !== fetchSequence || textStatus === 'abort') {
                        return;
                    }
                    var fallbackMessage = typeof errorThrown === 'string' && errorThrown !== ''
                        ? errorThrown
                        : 'Failed to load data';
                    if (debugEnabled) {
                        showError(extractAjaxErrorMessage(jqXHR, fallbackMessage));
                    } else {
                        showError('Failed to load table data: ' + fallbackMessage);
                    }
                },
                complete: function(jqXHR) {
                    if (activeFetchRequest === jqXHR) {
                        activeFetchRequest = null;
                        endLoadingState();
                    }
                }
            });

            activeFetchRequest = request;
            return request;
        }

        function showEditForm(row) {
            clearFormAlerts();

            var formMode = String(editForm.data('mode') || 'edit');
            var isCreateMode = formMode === 'create';

            if (viewOffcanvasInstance) {
                viewOffcanvasInstance.hide();
            }

            if (!row || typeof row !== 'object') {
                row = {};
            }

            var rowPrimaryKeyColumn = row.__fastcrud_primary_key || primaryKeyColumn;
            if (!rowPrimaryKeyColumn) {
                showFormError(isCreateMode ? 'Unable to determine primary key for creating records.' : 'Unable to determine primary key for editing.');
                return;
            }

            var primaryKeyValue = Object.prototype.hasOwnProperty.call(row, '__fastcrud_primary_value') && typeof row.__fastcrud_primary_value !== 'undefined'
                ? row.__fastcrud_primary_value
                : row[rowPrimaryKeyColumn];

            editForm.data('primaryKeyColumn', rowPrimaryKeyColumn);

            if (!primaryKeyColumn) {
                primaryKeyColumn = rowPrimaryKeyColumn;
            }

            if (isCreateMode) {
                editForm.data('primaryKeyValue', null);
            } else {
                if (primaryKeyValue === null || typeof primaryKeyValue === 'undefined' || String(primaryKeyValue).length === 0) {
                    showFormError('Missing primary key value for selected record.');
                    return;
                }
                editForm.data('primaryKeyValue', primaryKeyValue);
            }

            if (editLabel.length) {
                if (isCreateMode) {
                    editLabel.text(t('add_record', 'Add Record'));
                } else {
                    editLabel.text(t('edit_record_with_id', 'Edit Record {id}', { id: primaryKeyValue }));
                }
            }

            var submitButtons = editOffcanvasElement.find('button[type="submit"]');
            var submitButtonClose = editOffcanvasElement.find('.fastcrud-submit-close');
            var submitButtonNew = editOffcanvasElement.find('.fastcrud-submit-new');

            if (isCreateMode) {
                if (submitButtonClose.length) {
                    submitButtonClose.text('Create Record & Close');
                }
                if (submitButtonNew.length) {
                    submitButtonNew.text('Create Record & New').removeClass('d-none');
                }
            } else {
                if (submitButtonClose.length) {
                    submitButtonClose.text('Save Changes');
                }
                if (submitButtonNew.length) {
                    submitButtonNew.addClass('d-none');
                }
            }

            cancelPendingWidgets(editFieldsContainer);
            destroyRichEditors(editFieldsContainer);
            destroyFilePonds(editFieldsContainer);
            destroySelect2(editFieldsContainer);

            var editSectionRegistry = editFieldsContainer.data('fastcrud-section-keys');
            if (Array.isArray(editSectionRegistry)) {
                editSectionRegistry.forEach(function(key) {
                    editFieldsContainer.removeData(key);
                });
            }
            editFieldsContainer.removeData('fastcrud-section-keys');

            editFieldsContainer.empty();
            editForm.find('input[type="hidden"][data-fastcrud-field]').remove();

            var templateContext = $.extend({}, row);
            var customFieldHtml = row.__fastcrud_field_html && typeof row.__fastcrud_field_html === 'object'
                ? deepClone(row.__fastcrud_field_html)
                : {};

            var templateForMode = formMode ? getFormTemplate(formMode) : null;
            if (templateForMode && typeof templateForMode === 'object' && templateForMode.__fastcrud_field_html
                && typeof templateForMode.__fastcrud_field_html === 'object') {
                var templateHtml = deepClone(templateForMode.__fastcrud_field_html);
                Object.keys(templateHtml).forEach(function(key) {
                    if (!Object.prototype.hasOwnProperty.call(customFieldHtml, key)) {
                        customFieldHtml[key] = templateHtml[key];
                    }
                });
                row.__fastcrud_field_html = deepClone(customFieldHtml);
            }
            if (templateForMode && typeof templateForMode === 'object' && templateForMode.__fastcrud_field_permissions
                && typeof templateForMode.__fastcrud_field_permissions === 'object') {
                var templatePermissions = deepClone(templateForMode.__fastcrud_field_permissions);
                var rowPermissions = row.__fastcrud_field_permissions && typeof row.__fastcrud_field_permissions === 'object'
                    ? deepClone(row.__fastcrud_field_permissions)
                    : {};
                Object.keys(templatePermissions).forEach(function(key) {
                    if (!Object.prototype.hasOwnProperty.call(rowPermissions, key)) {
                        rowPermissions[key] = templatePermissions[key];
                    }
                });
                row.__fastcrud_field_permissions = rowPermissions;
            }

            var layout = buildFormLayout(formMode);
            var fields = layout.fields.slice();
            if (!fields.length) {
                var fallbackColumns = baseColumns.length ? baseColumns : columnsCache;
                fields = fallbackColumns
                    .filter(function(column) { return column !== rowPrimaryKeyColumn; })
                    .map(function(column) { return { name: column, tab: null, section: null }; });
            }

            var sectionMetaMap = {};
            if (layout && Array.isArray(layout.sections)) {
                layout.sections.forEach(function(section) {
                    if (!section || typeof section !== 'object' || !section.id) {
                        return;
                    }
                    sectionMetaMap[section.id] = section;
                });
            }

            var visibleFields = [];
            fields.forEach(function(field) {
                if (field.name === rowPrimaryKeyColumn) {
                    return;
                }
                if (!isFieldVisibleForRow(field.name, formMode, row)) {
                    return;
                }
                visibleFields.push(field);
            });

            var useTabs = Array.isArray(layout.tabs) && layout.tabs.length > 0 && visibleFields.some(function(field) {
                return !!field.tab;
            });
            var tabsNav = null;
            var tabsContent = null;
            var tabEntries = {};
            var defaultTabName = layout.defaultTab || null;

            if (useTabs) {
                tabsNav = $('<ul class="nav nav-tabs mb-3" role="tablist"></ul>');
                tabsContent = $('<div class="tab-content pe-1"></div>');
                editFieldsContainer.append(tabsNav).append(tabsContent);
            }

            function ensureTab(tabName) {
                if (!tabsNav || !tabsContent) {
                    return null;
                }

                if (!tabName) {
                    return null;
                }

                if (tabEntries[tabName]) {
                    return tabEntries[tabName];
                }

                var slug = makeSlug(tabName);
                var tabId = editFormId + '-tab-' + slug;
                var navItem = $('<li class="nav-item" role="presentation"></li>');
                var navButton = $('<button class="nav-link" data-bs-toggle="tab" type="button" role="tab"></button>')
                    .attr('id', tabId + '-tab')
                    .attr('data-bs-target', '#' + tabId)
                    .attr('aria-controls', tabId)
                    .attr('aria-selected', 'false')
                    .text(tabName);
                navItem.append(navButton);
                tabsNav.append(navItem);

                var pane = $('<div class="tab-pane fade p-1" role="tabpanel"></div>')
                    .attr('id', tabId)
                    .attr('aria-labelledby', tabId + '-tab');
                tabsContent.append(pane);

                tabEntries[tabName] = { nav: navButton, pane: pane };
                return tabEntries[tabName];
            }

            function getSectionMeta(sectionId) {
                if (!sectionId) {
                    return null;
                }
                if (Object.prototype.hasOwnProperty.call(sectionMetaMap, sectionId)) {
                    return sectionMetaMap[sectionId];
                }
                var fallback = {
                    id: sectionId,
                    title: makeLabel(sectionId),
                    description: null,
                    fields: [],
                    collapsible: false,
                    collapsed: false,
                    icon: null,
                    class: null,
                    title_class: null
                };
                sectionMetaMap[sectionId] = fallback;
                return fallback;
            }

            function ensureSectionContainer(parentContainer, sectionId) {
                if (!sectionId || !parentContainer || !parentContainer.length) {
                    return parentContainer;
                }

                var dataKey = 'fastcrud-section-' + sectionId;
                var cached = parentContainer.data(dataKey);
                if (cached && cached.body && cached.body.length) {
                    if (cached.wrapper && cached.wrapper.length
                        && parentContainer.length
                        && parentContainer[0]
                        && $.contains(parentContainer[0], cached.wrapper[0])) {
                        return cached.body;
                    }

                    parentContainer.removeData(dataKey);
                    var registry = parentContainer.data('fastcrud-section-keys');
                    if (Array.isArray(registry)) {
                        var index = registry.indexOf(dataKey);
                        if (index !== -1) {
                            registry.splice(index, 1);
                            parentContainer.data('fastcrud-section-keys', registry);
                        }
                    }
                }

                var meta = getSectionMeta(sectionId) || { id: sectionId };
                var title = typeof meta.title === 'string' && meta.title.length ? meta.title : makeLabel(sectionId);
                var description = typeof meta.description === 'string' && meta.description.length ? meta.description : null;
                var collapsible = !!meta.collapsible;
                var collapsed = collapsible && !!meta.collapsed;
                var iconClass = typeof meta.icon === 'string' && meta.icon.length ? meta.icon : null;
                var sectionClass = typeof meta.class === 'string' && meta.class.length ? meta.class : null;
                var titleClass = typeof meta.title_class === 'string' && meta.title_class.length ? meta.title_class : null;

                var wrapper = $('<div class="fastcrud-form-section mb-4"></div>')
                    .attr('data-fastcrud-section', sectionId);

                if (sectionClass) {
                    wrapper.addClass(sectionClass);
                }

                var header = null;
                if (title || description || collapsible) {
                    header = $('<div class="mb-2 fastcrud-form-section-header"></div>');
                    var headingGroup = $('<div class="fastcrud-form-section-heading"></div>');
                    if (titleClass) {
                        headingGroup.addClass(titleClass);
                    }

                    var hasHeadingContent = false;
                    if (title) {
                        var titleRow = $('<h5 class="fastcrud-form-section-title mb-0"></h5>');
                        if (iconClass) {
                            titleRow.append($('<i class="fastcrud-form-section-icon me-1"></i>').addClass(iconClass));
                        }
                        titleRow.append($('<span></span>').text(title));
                        headingGroup.append(titleRow);
                        hasHeadingContent = true;
                    } else if (iconClass && !title) {
                        headingGroup.append($('<i class="fastcrud-form-section-icon"></i>').addClass(iconClass));
                        hasHeadingContent = true;
                    }

                    if (description) {
                        headingGroup.append($('<div class="fastcrud-form-section-description text-muted small"></div>').text(description));
                        hasHeadingContent = true;
                    }

                    if (hasHeadingContent) {
                        header.append(headingGroup);
                    }

                    wrapper.append(header);
                }

                var body = $('<div class="fastcrud-form-section-body"></div>');
                wrapper.append(body);

                if (collapsible && header) {
                    var toggle = $('<button type="button" class="btn btn-sm btn-outline-secondary fastcrud-section-toggle" aria-expanded="true"></button>')
                        .html(collapsed ? actionIcons.expand : actionIcons.collapse)
                        .attr('aria-expanded', collapsed ? 'false' : 'true')
                        .attr('aria-label', collapsed ? 'Expand section' : 'Collapse section');
                    header.append(toggle);

                    if (collapsed) {
                        body.addClass('d-none');
                        wrapper.addClass('fastcrud-form-section-collapsed');
                        toggle.attr('aria-expanded', 'false');
                    }

                    toggle.on('click', function() {
                        var isCollapsed = body.hasClass('d-none');
                        if (isCollapsed) {
                            body.removeClass('d-none');
                            wrapper.removeClass('fastcrud-form-section-collapsed');
                            toggle.attr('aria-expanded', 'true')
                                .attr('aria-label', 'Collapse section')
                                .html(actionIcons.collapse);
                        } else {
                            body.addClass('d-none');
                            wrapper.addClass('fastcrud-form-section-collapsed');
                            toggle.attr('aria-expanded', 'false')
                                .attr('aria-label', 'Expand section')
                                .html(actionIcons.expand);
                        }
                    });
                }

                parentContainer.append(wrapper);
                parentContainer.data(dataKey, { wrapper: wrapper, body: body });

                var registry = parentContainer.data('fastcrud-section-keys');
                if (!Array.isArray(registry)) {
                    registry = [];
                }
                if (registry.indexOf(dataKey) === -1) {
                    registry.push(dataKey);
                    parentContainer.data('fastcrud-section-keys', registry);
                }

                return body;
            }

            if (useTabs) {
                layout.tabs.forEach(function(tabName) {
                    if (tabName) {
                        ensureTab(tabName);
                    }
                });
            }

            visibleFields.forEach(function(field) {
                var column = field.name;
                var behaviours = resolveBehavioursForField(column, formMode);
                if (!isFieldEditableForRow(column, formMode, row)) {
                    behaviours.readonly = true;
                }
                var changeMeta = behaviours.change_type || {};
                var changeType = String(changeMeta.type || 'text').toLowerCase();
                if (changeType === 'dropdown') {
                    changeType = 'select';
                }
                var params = changeMeta.params || {};
                if (!params || typeof params !== 'object') {
                    params = {};
                }
                var fieldId = editFormId + '-' + column;
                var labelForId = fieldId;
                var saveColumn = column;
                var fieldLabel = resolveFieldLabel(column);

                var currentValue = typeof row[column] !== 'undefined' && row[column] !== null ? row[column] : '';
                if (isCreateMode && (currentValue === null || currentValue === '') && typeof behaviours.pass_default !== 'undefined') {
                    currentValue = interpolateTemplate(behaviours.pass_default, templateContext);
                }
                if ((currentValue === null || currentValue === '') && typeof changeMeta.default !== 'undefined' && changeMeta.default !== null) {
                    currentValue = changeMeta.default;
                }
                if (typeof behaviours.pass_var !== 'undefined') {
                    currentValue = interpolateTemplate(behaviours.pass_var, templateContext);
                }
                if (currentValue === null || typeof currentValue === 'undefined') {
                    currentValue = '';
                }

                templateContext[column] = currentValue;

                if (changeType === 'hidden') {
                    var hiddenInput = $('<input type="hidden" />')
                        .attr('id', fieldId)
                        .attr('data-fastcrud-field', column)
                        .attr('data-fastcrud-type', 'hidden')
                        .val(currentValue);
                    editForm.append(hiddenInput);
                    return;
                }

                var container = editFieldsContainer;
                if (useTabs) {
                    var targetTab = field.tab || defaultTabName || (layout.tabs.length ? layout.tabs[0] : null);
                    if (targetTab) {
                        var entry = ensureTab(targetTab);
                        if (entry && entry.pane) {
                            container = entry.pane;
                        }
                    }
                }

                var sectionId = field.section || null;
                var targetContainer = container;
                if (sectionId) {
                    targetContainer = ensureSectionContainer(container, sectionId);
                }

                if (typeof customFieldHtml[column] !== 'undefined') {
                    var customContainer = $('<div class="mb-3"></div>').attr('data-fastcrud-group', column);
                    var htmlContent = customFieldHtml[column];
                    var implicitLabel = false;

                    if (typeof htmlContent === 'string') {
                        implicitLabel = /<label\b/i.test(htmlContent);
                    } else if (htmlContent && (htmlContent.jquery || htmlContent.nodeType === 1)) {
                        var contentProbe = htmlContent.jquery ? htmlContent : $(htmlContent);
                        implicitLabel = contentProbe.is('label') || contentProbe.find('label').length > 0;
                    }

                    if (fieldLabel !== '' && !implicitLabel) {
                        customContainer.append($('<label class="form-label"></label>').text(fieldLabel));
                    } else if (fieldLabel === '') {
                        customContainer.addClass('fastcrud-field-no-label');
                    }

                    if (htmlContent && typeof htmlContent === 'object' && htmlContent.jquery) {
                        customContainer.append(htmlContent);
                    } else if (htmlContent && htmlContent.nodeType === 1 && typeof htmlContent.cloneNode === 'function') {
                        customContainer.append(htmlContent);
                    } else if (typeof htmlContent === 'string') {
                        if (htmlContent.indexOf('<') !== -1) {
                            customContainer.append(htmlContent);
                        } else {
                            customContainer.text(htmlContent);
                        }
                    } else {
                        customContainer.append(htmlContent);
                    }
                    targetContainer.append(customContainer);

                    // Sync value attributes inside custom markup with the resolved current value
                    var valueHolders = customContainer.find('[data-fastcrud-field="' + column + '"]');
                    if (valueHolders.length) {
                        valueHolders.each(function() {
                            var el = $(this);
                            var tagName = el.prop('tagName');
                            if (tagName === 'INPUT' || tagName === 'TEXTAREA' || tagName === 'SELECT') {
                                el.val(currentValue);
                            } else {
                                el.text(currentValue != null ? String(currentValue) : '');
                            }
                        });
                    }
                    return;
                }

                var group = $('<div class="mb-3"></div>').attr('data-fastcrud-group', column);
                var input;
                var compound = null; // optional wrapper for composite inputs (e.g., color)
                var colorPicker = null; // used when changeType === 'color'
                var dataType = changeType;
                var normalizedValue = currentValue;
                var hasExistingPassword = false;
                var applyNativeRequired = true;

                if (changeType === 'textarea') {
                    input = $('<textarea class="form-control"></textarea>')
                        .attr('id', fieldId)
                        .attr('rows', params.rows && Number(params.rows) > 0 ? Number(params.rows) : 3)
                        .val(normalizedValue);
                } else if (changeType === 'rich_editor') {
                    var startingValue = normalizedValue === null || typeof normalizedValue === 'undefined'
                        ? ''
                        : String(normalizedValue);
                    input = $('<textarea class="form-control editor-instance fastcrud-rich-editor"></textarea>')
                        .attr('id', fieldId)
                        .attr('rows', params.rows && Number(params.rows) > 0 ? Number(params.rows) : 6)
                        .val(startingValue);
                    dataType = 'rich_editor';

                    var editorConfig = {};
                    if (typeof params.height !== 'undefined' && params.height !== null) {
                        var heightCandidate = params.height;
                        var numericHeight = Number(heightCandidate);
                        editorConfig.height = Number.isFinite(numericHeight) && numericHeight > 0 ? numericHeight : heightCandidate;
                    }
                    if (params.editor && typeof params.editor === 'object') {
                        editorConfig = $.extend(true, editorConfig, params.editor);
                    }
                    if (!$.isEmptyObject(editorConfig)) {
                        input.data('fastcrudEditorConfig', editorConfig);
                    }
                } else if (changeType === 'json') {
                    // JSON editor: textarea with optional pretty-print and live validation
                    var jsonText = '';
                    try {
                        var s = (normalizedValue === null || typeof normalizedValue === 'undefined') ? '' : String(normalizedValue);
                        var t = s.trim();
                        if (t.length) {
                            var parsed = JSON.parse(t);
                            if (params.pretty === false) {
                                jsonText = t;
                            } else {
                                jsonText = JSON.stringify(parsed, null, 2);
                            }
                        } else {
                            jsonText = '';
                        }
                    } catch (e) {
                        jsonText = String(normalizedValue || '');
                    }
                    input = $('<textarea class="form-control fastcrud-json" style="font-family: monospace;"></textarea>')
                        .attr('id', fieldId)
                        .attr('rows', params.rows && Number(params.rows) > 0 ? Number(params.rows) : 6)
                        .val(jsonText);
                    // Lightweight live validation for JSON content
                    input.on('input blur', function() {
                        var el = $(this);
                        var value = String(el.val() || '').trim();
                        if (value === '') {
                            clearInlineFieldError(el);
                            return;
                        }
                        try {
                            JSON.parse(value);
                            clearInlineFieldError(el);
                        } catch (jsonError) {
                            setInlineFieldError(el, buildJsonErrorMessage(jsonError));
                        }
                    });
                    dataType = 'json';
                } else if (changeType === 'select') {
                    input = $('<select class="form-select"></select>').attr('id', fieldId);
                    var optionMap = params.values || params.options || {};
                    var optionsList = [];
                    if ($.isArray(optionMap)) {
                        optionMap.forEach(function(optionValue) {
                            optionsList.push({ value: optionValue, label: optionValue });
                        });
                    } else if (typeof optionMap === 'object') {
                        Object.keys(optionMap).forEach(function(key) {
                            optionsList.push({ value: key, label: optionMap[key] });
                        });
                    }
                    if (params.placeholder) {
                        input.append($('<option></option>').attr('value', '').text(params.placeholder));
                    }
                    optionsList.forEach(function(option) {
                        input.append($('<option></option>').attr('value', option.value).text(option.label));
                    });
                    input.val(String(normalizedValue));
                } else if (changeType === 'radio') {
                    dataType = 'radio';
                    var radioOptionMap = params.values || params.options || {};
                    var radioOptions = [];
                    if ($.isArray(radioOptionMap)) {
                        radioOptionMap.forEach(function(optionValue) {
                            radioOptions.push({ value: optionValue, label: optionValue });
                        });
                    } else if (typeof radioOptionMap === 'object') {
                        Object.keys(radioOptionMap).forEach(function(key) {
                            radioOptions.push({ value: key, label: radioOptionMap[key] });
                        });
                    }
                    var selectedRadioValue = '';
                    if ($.isArray(normalizedValue) && normalizedValue.length) {
                        selectedRadioValue = String(normalizedValue[0]);
                    } else if (normalizedValue !== null && typeof normalizedValue !== 'undefined') {
                        selectedRadioValue = String(normalizedValue);
                    }
                    var radioValueInput = $('<input type="hidden" />')
                        .attr('id', fieldId)
                        .val(selectedRadioValue);
                    input = radioValueInput;
                    var radioGroup = $('<div class="fastcrud-radio-group"></div>');
                    var radioControls = $();
                    var radioName = fieldId + '-choice';
                    radioOptions.forEach(function(option, index) {
                        var radioId = fieldId + '-radio-' + index;
                        var radioWrapper = $('<div class="form-check"></div>');
                        if (params.inline) {
                            radioWrapper.addClass('form-check-inline');
                        }
                        var radio = $('<input type="radio" class="form-check-input" />')
                            .attr('name', radioName)
                            .attr('id', radioId)
                            .attr('value', option.value);
                        if (selectedRadioValue !== '' && String(option.value) === selectedRadioValue) {
                            radio.prop('checked', true);
                        }
                        var radioLabel = $('<label class="form-check-label"></label>')
                            .attr('for', radioId)
                            .text(option.label);
                        radioWrapper.append(radio).append(radioLabel);
                        radioGroup.append(radioWrapper);
                        radioControls = radioControls.add(radio);
                    });
                    var syncRadioValue = function() {
                        var checked = radioControls.filter(':checked');
                        if (checked.length) {
                            radioValueInput.val(String(checked.first().val() || '')).trigger('change');
                        } else {
                            radioValueInput.val('').trigger('change');
                        }
                    };
                    radioControls.on('change', syncRadioValue);
                    syncRadioValue();
                    input.data('fastcrudExtraElements', radioGroup);
                    input.data('fastcrudControls', radioControls);
                } else if (changeType === 'multicheckbox' || changeType === 'multi_checkbox') {
                    dataType = 'multicheckbox';
                    var checkboxOptionMap = params.values || params.options || {};
                    var checkboxOptions = [];
                    if ($.isArray(checkboxOptionMap)) {
                        checkboxOptionMap.forEach(function(optionValue) {
                            checkboxOptions.push({ value: optionValue, label: optionValue });
                        });
                    } else if (typeof checkboxOptionMap === 'object') {
                        Object.keys(checkboxOptionMap).forEach(function(key) {
                            checkboxOptions.push({ value: key, label: checkboxOptionMap[key] });
                        });
                    }
                    var selectedCheckboxValues = [];
                    if ($.isArray(normalizedValue)) {
                        selectedCheckboxValues = normalizedValue.map(function(item) { return String(item); });
                    } else if (typeof normalizedValue === 'string') {
                        selectedCheckboxValues = normalizedValue.split(',').map(function(item) {
                            return String(item).trim();
                        }).filter(function(item) { return item.length > 0; });
                    } else if (normalizedValue !== null && typeof normalizedValue !== 'undefined') {
                        selectedCheckboxValues = [String(normalizedValue)];
                    }
                    var checkboxValueInput = $('<input type="hidden" />')
                        .attr('id', fieldId)
                        .val(selectedCheckboxValues.join(','));
                    input = checkboxValueInput;
                    var checkboxGroup = $('<div class="fastcrud-multicheckbox-group"></div>');
                    var checkboxControls = $();
                    checkboxOptions.forEach(function(option, index) {
                        var checkboxId = fieldId + '-checkbox-' + index;
                        var checkboxWrapper = $('<div class="form-check"></div>');
                        if (params.inline) {
                            checkboxWrapper.addClass('form-check-inline');
                        }
                        var checkbox = $('<input type="checkbox" class="form-check-input" />')
                            .attr('id', checkboxId)
                            .attr('value', option.value)
                            .attr('name', fieldId + '[]');
                        if (selectedCheckboxValues.indexOf(String(option.value)) !== -1) {
                            checkbox.prop('checked', true);
                        }
                        var checkboxLabel = $('<label class="form-check-label"></label>')
                            .attr('for', checkboxId)
                            .text(option.label);
                        checkboxWrapper.append(checkbox).append(checkboxLabel);
                        checkboxGroup.append(checkboxWrapper);
                        checkboxControls = checkboxControls.add(checkbox);
                    });
                    var syncCheckboxValues = function() {
                        var values = [];
                        checkboxControls.each(function() {
                            var el = $(this);
                            if (el.is(':checked')) {
                                values.push(String(el.val() || ''));
                            }
                        });
                        checkboxValueInput.val(values.join(',')).trigger('change');
                    };
                    checkboxControls.on('change', syncCheckboxValues);
                    syncCheckboxValues();
                    input.data('fastcrudExtraElements', checkboxGroup);
                    input.data('fastcrudControls', checkboxControls);
                } else if (changeType === 'image' || changeType === 'images') {
                    var isMultipleImages = changeType === 'images';
                    // Always use the declared column; no mapping via params.save_to or base column checks

                    var normalizedList = parseImageNameList(currentValue);
                    var uploadSubPath = normalizeUploadSubPath(params.path);
                    if (uploadSubPath) {
                        normalizedList = normalizedList.map(function(name) {
                            if (!name) {
                                return '';
                            }
                            if (name.indexOf('/') === -1 && name.indexOf('\\') === -1) {
                                return normalizeStoredImageName(uploadSubPath + '/' + name);
                            }
                            return normalizeStoredImageName(name);
                        }).filter(function(name) { return !!name; });
                    }
                    var initialValueString = isMultipleImages
                        ? imageNamesToString(normalizedList)
                        : (normalizedList.length ? normalizedList[0] : '');
                    if (!isMultipleImages) {
                        normalizedList = initialValueString ? [initialValueString] : [];
                    }
                    normalizedValue = initialValueString;

                    // Use FilePond for image uploads with preview; store value in a hidden field
                    var hiddenInput = $('<input type="hidden" />')
                        .attr('id', fieldId)
                        .attr('data-fastcrud-field', saveColumn)
                        .attr('data-fastcrud-type', 'hidden')
                        .val(String(initialValueString || ''));
                    input = $('<input type="file" class="fastcrud-filepond" accept="image/*" />')
                        .attr('id', fieldId + '-file');
                    if (isMultipleImages) {
                        input.attr('multiple', 'multiple');
                    }
                    labelForId = fieldId + '-file';
                    dataType = changeType;
                } else if (changeType === 'file' || changeType === 'files') {
                    var isMultipleFiles = (changeType === 'files');
                    var normalizedListFiles = parseImageNameList(currentValue);
                    var uploadSubPathFiles = normalizeUploadSubPath(params.path);
                    if (uploadSubPathFiles) {
                        normalizedListFiles = normalizedListFiles.map(function(name) {
                            if (!name) {
                                return '';
                            }
                            if (name.indexOf('/') === -1 && name.indexOf('\\') === -1) {
                                return normalizeStoredImageName(uploadSubPathFiles + '/' + name);
                            }
                            return normalizeStoredImageName(name);
                        }).filter(function(name) { return !!name; });
                    }
                    var initialFilesValue = isMultipleFiles
                        ? imageNamesToString(normalizedListFiles)
                        : (normalizedListFiles.length ? normalizedListFiles[0] : '');
                    var hiddenInput = $('<input type="hidden" />')
                        .attr('id', fieldId)
                        .attr('data-fastcrud-field', saveColumn)
                        .attr('data-fastcrud-type', 'hidden')
                        .val(String(initialFilesValue || ''));
                    input = $('<input type="file" class="fastcrud-filepond" />')
                        .attr('id', fieldId + '-file');
                    if (isMultipleFiles) {
                        input.attr('multiple', 'multiple');
                    }
                    if (params.accept) {
                        input.attr('accept', params.accept);
                    }
                    labelForId = fieldId + '-file';
                    dataType = isMultipleFiles ? 'files' : 'file';
                } else if (changeType === 'multiselect') {
                    input = $('<select class="form-select" multiple></select>').attr('id', fieldId);
                    var multiMap = params.values || params.options || {};
                    var multiOptions = [];
                    if ($.isArray(multiMap)) {
                        multiMap.forEach(function(optionValue) {
                            multiOptions.push({ value: optionValue, label: optionValue });
                        });
                    } else if (typeof multiMap === 'object') {
                        Object.keys(multiMap).forEach(function(key) {
                            multiOptions.push({ value: key, label: multiMap[key] });
                        });
                    }
                    multiOptions.forEach(function(option) {
                        input.append($('<option></option>').attr('value', option.value).text(option.label));
                    });
                    var selectedValues;
                    if ($.isArray(normalizedValue)) {
                        selectedValues = normalizedValue;
                    } else {
                        selectedValues = String(normalizedValue).split(',').map(function(value) {
                            return value.trim();
                        }).filter(function(value) {
                            return value.length > 0;
                        });
                    }
                    input.val(selectedValues);
                } else if (changeType === 'date' || changeType === 'datetime' || changeType === 'datetime-local') {
                    input = $('<input class="form-control" />')
                        .attr('id', fieldId)
                        .attr('type', changeType === 'date' ? 'date' : 'datetime-local')
                        .val(String(normalizedValue));
                    dataType = changeType === 'date' ? 'date' : 'datetime';
                } else if (changeType === 'time') {
                    input = $('<input type="time" class="form-control" />')
                        .attr('id', fieldId)
                        .val(String(normalizedValue));
                } else if (changeType === 'email') {
                    input = $('<input type="email" class="form-control" />')
                        .attr('id', fieldId)
                        .val(String(normalizedValue));
                } else if (changeType === 'color') {
                    var startColor = String(normalizedValue || '').trim();
                    if (!startColor) { startColor = '#000000'; }
                    // Text input is the value holder submitted to server
                    input = $('<input type="text" class="form-control" />')
                        .attr('id', fieldId)
                        .attr('placeholder', '#RRGGBB')
                        .val(startColor);
                    dataType = 'color';
                    // Color picker on the left inside an input-group
                    colorPicker = $('<input type="color" class="form-control form-control-color" />')
                        .attr('id', fieldId + '-picker')
                        .val(startColor)
                        .css({ minWidth: '3rem' });
                    compound = $('<div class="input-group align-items-stretch"></div>');
                    var addon = $('<span class="input-group-text p-0"></span>');
                    addon.append(colorPicker);
                    compound.append(addon).append(input);
                    // Keep values in sync both ways
                    colorPicker.on('input change', function() {
                        try { input.val(String(colorPicker.val() || '')).trigger('input').trigger('change'); } catch (e) {}
                    });
                    input.on('input change', function() {
                        try {
                            var v = String(input.val() || '').trim();
                            if (/^#([0-9a-fA-F]{6})$/.test(v)) { colorPicker.val(v); }
                        } catch (e) {}
                    });
                    // Let the browser open the picker when the swatch is clicked; keep manual edits possible
                } else if (changeType === 'number' || changeType === 'int' || changeType === 'integer' || changeType === 'float' || changeType === 'decimal') {
                    input = $('<input type="number" class="form-control" />')
                        .attr('id', fieldId)
                        .val(String(normalizedValue));
                    if (params.step) {
                        input.attr('step', params.step);
                    }
                    if (params.min) {
                        input.attr('min', params.min);
                    }
                    if (params.max) {
                        input.attr('max', params.max);
                    }
                    dataType = 'number';
                } else if (changeType === 'password') {
                    input = $('<input type="password" class="form-control" />')
                        .attr('id', fieldId)
                        .val('');
                    dataType = 'password';
                    if (formMode !== 'create' && normalizedValue !== null && normalizedValue !== '') {
                        hasExistingPassword = true;
                        input.attr('data-fastcrud-password-existing', '1');
                    }
                } else if (changeType === 'bool' || changeType === 'checkbox' || changeType === 'switch') {
                    group.removeClass('mb-3').addClass('form-check mb-3');
                    input = $('<input type="checkbox" class="form-check-input" />')
                        .attr('id', fieldId)
                        .attr('data-fastcrud-field', column)
                        .attr('data-fastcrud-type', 'checkbox');
                    var isChecked = normalizedValue === true || normalizedValue === 1 || normalizedValue === '1' || normalizedValue === 'true';
                    input.prop('checked', isChecked);
                    var checkboxLabel = null;
                    if (fieldLabel !== '') {
                        checkboxLabel = $('<label class="form-check-label"></label>')
                            .attr('for', fieldId)
                            .text(fieldLabel);
                    } else {
                        group.addClass('fastcrud-field-no-label');
                    }
                    group.append(input);
                    if (checkboxLabel) {
                        group.append(checkboxLabel);
                    }
                    dataType = 'checkbox';
                } else {
                    input = $('<input type="text" class="form-control" />')
                        .attr('id', fieldId)
                        .val(String(normalizedValue));
                    dataType = 'text';
                }

                if (!input) {
                    return;
                }

                if (changeType !== 'bool' && changeType !== 'checkbox' && changeType !== 'switch') {
                    if (fieldLabel !== '') {
                        group.append($('<label class="form-label"></label>').attr('for', labelForId).text(fieldLabel));
                    } else {
                        group.addClass('fastcrud-field-no-label');
                    }
                    if (changeType === 'color' && compound) {
                        group.append(compound);
                    } else {
                        group.append(input);
                    }
                    if (changeType === 'image' || changeType === 'images' || changeType === 'file' || changeType === 'files') {
                        // Append the hidden value holder so it gets included on submit
                        group.append(hiddenInput);
                    }

                    var extraElements = input.data && typeof input.data === 'function'
                        ? input.data('fastcrudExtraElements')
                        : null;
                    if (extraElements) {
                        if ($.isArray(extraElements)) {
                            extraElements.forEach(function(element) {
                                if (!element) {
                                    return;
                                }
                                if (element.jquery) {
                                    group.append(element);
                                } else {
                                    group.append($(element));
                                }
                            });
                        } else if (extraElements.jquery) {
                            group.append(extraElements);
                        } else {
                            group.append($(extraElements));
                        }
                    }
                }

                if (params.placeholder && input.is('input, textarea')) {
                    input.attr('placeholder', params.placeholder);
                }

                var hasBehaviourMaxLength = typeof behaviours.max_length !== 'undefined';
                var maxLength = parseInt(hasBehaviourMaxLength ? behaviours.max_length : params.maxlength, 10);
                if (!Number.isNaN(maxLength) && maxLength > 0 && input.is('input, textarea')) {
                    input.attr('data-fastcrud-max-length', maxLength);
                    if (!hasBehaviourMaxLength) {
                        input.attr('maxlength', maxLength);
                    }

                    var charCounter = $('<div class="form-text fastcrud-char-counter text-muted"></div>');
                    input.data('fastcrudCharCounter', charCounter);
                    input.on('input change', function() {
                        updateCharCounter($(this));
                    });
                    group.append(charCounter);
                    updateCharCounter(input);
                }

                if (params.class) {
                    input.addClass(params.class);
                }

                if (changeType !== 'image' && changeType !== 'images' && changeType !== 'file' && changeType !== 'files') {
                    input.attr('data-fastcrud-field', column);
                    input.attr('data-fastcrud-type', dataType);
                }

                if (behaviours.validation_required) {
                    input.attr('data-fastcrud-required', behaviours.validation_required);
                    if (changeType === 'password' && hasExistingPassword && formMode !== 'create') {
                        applyNativeRequired = false;
                    }
                    if (applyNativeRequired && !input.is(':checkbox')) {
                        input.attr('required', 'required');
                    }
                }

                if (behaviours.validation_pattern) {
                    input.attr('data-fastcrud-pattern', behaviours.validation_pattern);
                    if (typeof behaviours.validation_pattern === 'string' && behaviours.validation_pattern.length) {
                        var htmlPattern = behaviours.validation_pattern;
                        var delimiter = htmlPattern.charAt(0);
                        var lastIndex = htmlPattern.lastIndexOf(delimiter);
                        if ((delimiter === '/' || delimiter === '#') && lastIndex > 0) {
                            htmlPattern = htmlPattern.slice(1, lastIndex);
                        }
                        if (htmlPattern) {
                            input.attr('pattern', htmlPattern);
                        }
                    }
                }

                if (behaviours.readonly) {
                    if (input.is('select')) {
                        input.prop('disabled', true);
                    } else {
                        input.prop('readonly', true);
                    }
                    group.addClass('fastcrud-field-readonly');
                    if (changeType === 'color' && colorPicker) {
                        try { colorPicker.prop('disabled', true); } catch (e) {}
                    }
                    if (changeType === 'image' || changeType === 'images' || changeType === 'file' || changeType === 'files') {
                        // Prevent posting hidden value when field is readonly
                        try { hiddenInput.prop('disabled', true); } catch (e) {}
                    }
                }

                if (behaviours.disabled) {
                    input.prop('disabled', true);
                    group.addClass('fastcrud-field-disabled');
                    if (changeType === 'color' && colorPicker) {
                        try { colorPicker.prop('disabled', true); } catch (e) {}
                    }
                    if (changeType === 'image' || changeType === 'images' || changeType === 'file' || changeType === 'files') {
                        try { hiddenInput.prop('disabled', true); } catch (e) {}
                    }
                }

                var attachedControls = input.data && typeof input.data === 'function'
                    ? input.data('fastcrudControls')
                    : null;
                if (attachedControls && attachedControls.length) {
                    if (behaviours.validation_required) {
                        attachedControls.attr('required', 'required');
                    }
                    if (behaviours.readonly || behaviours.disabled) {
                        attachedControls.prop('disabled', true);
                    }
                    if (params.class) {
                        attachedControls.addClass(params.class);
                    }
                }

                if (behaviours.pass_var) {
                    input.attr('data-fastcrud-pass-var', behaviours.pass_var);
                }
                if (isCreateMode && behaviours.pass_default) {
                    input.attr('data-fastcrud-pass-default', behaviours.pass_default);
                }
                if (behaviours.unique) {
                    input.attr('data-fastcrud-unique', '1');
                }

                targetContainer.append(group);

                if (changeType === 'image' || changeType === 'images') {
                    // Initialize FilePond after appending to DOM
                    withVisibleFilePondAssets(group, function() {
                        var fileInput = group.find('#' + $.escapeSelector(fieldId + '-file'));
                        var valueInput = group.find('#' + $.escapeSelector(fieldId));
                        if (!fileInput.length || !valueInput.length || !window.FilePond) {
                            return;
                        }

                        try {
                            var isMultipleImages = changeType === 'images';
                            if (isMultipleImages) {
                                setImageNamesOnInput(valueInput, valueInput.val());
                            } else {
                                var singleNames = parseImageNameList(valueInput.val());
                                if (singleNames.length > 1) {
                                    valueInput.val(singleNames[0]);
                                } else if (singleNames.length === 1) {
                                    valueInput.val(singleNames[0]);
                                } else {
                                    valueInput.val('');
                                }
                                clearImageNameMap(valueInput);
                            }

                            var currentNames = parseImageNameList(valueInput.val());
                            var initialFiles = currentNames.map(function(name) {
                                var url = toPublicUrl(name);
                                return {
                                    source: url,
                                    options: {
                                        type: 'local',
                                        file: { name: name, size: 0 },
                                        metadata: { poster: url, storedName: name }
                                    }
                                };
                            });

                            if (isMultipleImages) {
                                var initialMap = ensureImageNameMap(valueInput);
                                initialFiles.forEach(function(item) {
                                    var key = item && item.source ? item.source : '';
                                    var storedName = item && item.options && item.options.metadata ? item.options.metadata.storedName : '';
                                    if (key && storedName) {
                                        initialMap[key] = storedName;
                                    }
                                });
                            }

                            var showFilePondUploadError = function(message) {
                                setFilePondFieldError(fileInput, valueInput, message);
                            };
                            var clearFilePondUploadError = function() {
                                setFilePondFieldError(fileInput, valueInput, '');
                            };
                            var uploadMaxSize = resolveUploadMaxSize(params, true);

                            var stylePanelAspect = (params.panelAspectRatio || params.aspectRatio);
                            var imageIdleLabel = isMultipleImages
                                ? 'Drop images here or <span class="filepond--label-action">Browse</span>'
                                : 'Drop an image here or <span class="filepond--label-action">Browse</span>';
                            var pond = window.FilePond.create(fileInput.get(0), {
                                allowMultiple: isMultipleImages,
                                allowReorder: isMultipleImages,
                                allowImagePreview: true,
                                imagePreviewHeight: params.previewHeight ? Number(params.previewHeight) : (isMultipleImages ? 150 : 190),
                                allowFilePoster: true,
                                filePosterHeight: params.posterHeight ? Number(params.posterHeight) : (isMultipleImages ? 120 : 170),
                                stylePanelAspectRatio: stylePanelAspect || undefined,
                                labelIdle: imageIdleLabel,
                                labelFileLoading: 'Loading',
                                labelFileProcessing: 'Uploading',
                                labelFileProcessingComplete: 'Uploaded',
                                labelFileProcessingAborted: 'Upload cancelled',
                                labelFileProcessingError: 'Upload failed',
                                labelTapToCancel: 'tap to cancel',
                                labelTapToRetry: 'tap to retry',
                                labelTapToUndo: 'tap to remove',
                                labelButtonRemoveItem: 'Remove',
                                credits: false,
                                files: initialFiles,
                                server: {
                                    process: function(fieldName, file, metadata, load, error, progress, abort) {
                                        if (uploadMaxSize && file && typeof file.size === 'number' && file.size > uploadMaxSize.size) {
                                            var sizeMessage = buildUploadTooLargeMessage(true, uploadMaxSize);
                                            showFilePondUploadError(sizeMessage);
                                            error(sizeMessage);
                                            return { abort: function() { abort(); } };
                                        }
                                        var xhr = new XMLHttpRequest();
                                        xhr.open('POST', window.location.pathname);
                                        xhr.withCredentials = true;
                                        xhr.upload.onprogress = function(e) {
                                            progress(e.lengthComputable, e.loaded, e.total);
                                        };
                                        xhr.onload = function() {
                                            if (xhr.status < 200 || xhr.status >= 300) {
                                                var statusMessage = 'Upload failed with status ' + xhr.status;
                                                showFilePondUploadError(statusMessage);
                                                error(statusMessage);
                                                return;
                                            }
                                            var response;
                                            var raw = xhr.responseText || '';
                                            try { response = JSON.parse(raw || '{}'); } catch (e) {
                                                showFilePondUploadError('Upload returned invalid JSON.');
                                                error('Upload returned invalid JSON.');
                                                return;
                                            }
                                            if (!response || response.success !== true || !response.location) {
                                                var responseMessage = response && response.error ? response.error : 'Upload failed.';
                                                showFilePondUploadError(responseMessage);
                                                error(responseMessage);
                                                return;
                                            }
                                            clearFilePondUploadError();

                                            var storedName = '';
                                            if (response.name) {
                                                storedName = String(response.name);
                                            }
                                            if (!storedName && response.location) {
                                                storedName = extractFileName(response.location);
                                            }
                                            if (!storedName && file && file.name) {
                                                storedName = extractFileName(file.name);
                                            }

                                                if (storedName) {
                                                    if (isMultipleImages) {
                                                        addImageNameToInput(valueInput, storedName);
                                                    } else {
                                                        valueInput.val(normalizeStoredImageName(storedName));
                                                    }
                                                valueInput.trigger('change');
                                            }

                                            var serverKey = response.location ? String(response.location) : storedName;
                                            if (isMultipleImages && serverKey) {
                                                mapImageNameToKey(valueInput, serverKey, storedName);
                                            }

                                            load(serverKey || storedName || '');
                                        };
                                        xhr.onerror = function() {
                                            showFilePondUploadError('Upload failed due to a network error.');
                                            error('Upload failed due to a network error.');
                                        };
                                        var formData = new FormData();
                                        formData.append('file', file, file.name);
                                        formData.append('fastcrud_ajax', '1');
                                        formData.append('action', 'upload_filepond');
                                        formData.append('kind', 'image');
                                        if (tableName) { formData.append('table', tableName); }
                                        if (tableId) { formData.append('id', tableId); }
                                        formData.append('column', saveColumn);
                                        appendCrudConfigToFormData(formData, false);
                                        xhr.send(formData);
                                        return { abort: function() { xhr.abort(); abort(); } };
                                    },
                                    fetch: function(url, load, error, progress, abort) {
                                        try {
                                            var target = url;
                                            if (target && typeof target === 'string' && !/^https?:\/\//i.test(target) && target.charAt(0) !== '/' && !/^blob:/i.test(target) && !/^data:/i.test(target)) {
                                                target = toPublicUrl(target);
                                            }
                                            var xhr = new XMLHttpRequest();
                                            xhr.open('GET', target);
                                            xhr.responseType = 'blob';
                                            xhr.onload = function() { load(xhr.response); };
                                            xhr.onerror = function() { error('Failed to fetch image.'); };
                                            xhr.onprogress = function(e) { progress(e.lengthComputable, e.loaded, e.total); };
                                            xhr.send();
                                            return { abort: function() { try { xhr.abort(); } catch (e) {} abort(); } };
                                        } catch (e) {
                                            error('Failed to fetch image.');
                                            abort();
                                        }
                                    },
                                    revert: function(uniqueId, load, error) {
                                        if (isMultipleImages) {
                                            removeImageNameFromInput(valueInput, uniqueId);
                                            removeImageNameForKey(valueInput, uniqueId);
                                            valueInput.trigger('change');
                                        } else {
                                            valueInput.val('');
                                            valueInput.trigger('change');
                                            clearImageNameMap(valueInput);
                                        }
                                        load();
                                    },
                                    load: function(source, load, error, progress, abort) {
                                        try {
                                            var target = source;
                                            if (target && typeof target === 'string' && !/^https?:\/\//i.test(target) && target.charAt(0) !== '/' && !/^blob:/i.test(target) && !/^data:/i.test(target)) {
                                                target = toPublicUrl(target);
                                            }
                                            var xhr = new XMLHttpRequest();
                                            xhr.open('GET', target);
                                            xhr.responseType = 'blob';
                                            xhr.onload = function() { load(xhr.response); };
                                            xhr.onerror = function() { error('Failed to load image.'); };
                                            xhr.onprogress = function(e) { progress(e.lengthComputable, e.loaded, e.total); };
                                            xhr.send();
                                            return { abort: function() { xhr.abort(); abort(); } };
                                        } catch (e) {
                                            error('Failed to load image.');
                                            abort();
                                        }
                                    }
                                }
                            });
                            hydrateFilePondInitialSizes(pond, initialFiles);
                            try {
                                $(pond.element)
                                    .addClass('fastcrud-filepond-uploader')
                                    .addClass(isMultipleImages ? 'fastcrud-filepond--multi-image' : 'fastcrud-filepond--single-image');
                            } catch (e) {}
                            pond.on('addfile', function() {
                                clearFilePondUploadError();
                            });
                            pond.on('removefile', function() {
                                clearFilePondUploadError();
                            });

                            // Apply width: use full width for multi-image grids by default
                            var pondWidth = (function() {
                                var explicit = (params.width || params.pondWidth || params.previewWidth || '').toString().trim();
                                if (explicit) return explicit;
                                return isMultipleImages ? '100%' : '22rem';
                            })();
                            // Try to enforce max width robustly (some FilePond updates adjust inline styles)
                            try {
                                var applyPondWidth = function(el, value) {
                                    try {
                                        el.style.setProperty('max-width', String(value), 'important');
                                        el.style.setProperty('width', '100%', 'important');
                                        // ensure it can shrink from block-level width if needed
                                        el.style.setProperty('display', 'block');
                                    } catch (e) {}
                                };
                                applyPondWidth(pond.element, pondWidth);
                                // Re-apply on next tick and when pond is ready (in case FilePond mutates styles)
                                setTimeout(function() { applyPondWidth(pond.element, pondWidth); }, 0);
                                if (pond && typeof pond.on === 'function') {
                                    pond.on('ready', function() { applyPondWidth(pond.element, pondWidth); });
                                }
                            } catch (e) {}

                            if (isMultipleImages) {
                                pond.on('removefile', function(error, file) {
                                    if (!file) {
                                        return;
                                    }
                                    var key = file.serverId || file.source || '';
                                    var storedName = '';
                                    if (file.getMetadata && typeof file.getMetadata === 'function') {
                                        storedName = file.getMetadata('storedName') || '';
                                    }
                                    if (!storedName) {
                                        storedName = findImageNameForKey(valueInput, key) || extractFileName(key || file.filename);
                                    }
                                    if (storedName) {
                                        removeImageNameFromInput(valueInput, storedName);
                                        valueInput.trigger('change');
                                    }
                                    if (key) {
                                        removeImageNameForKey(valueInput, key);
                                    }
                                });
                                // Keep hidden input order in sync when user reorders items
                                pond.on('reorderfiles', function(files) {
                                    try {
                                        var ordered = [];
                                        (files || pond.getFiles() || []).forEach(function(item) {
                                            if (!item) return;
                                            var key = item.serverId || item.source || '';
                                            var name = '';
                                            if (item.getMetadata && typeof item.getMetadata === 'function') {
                                                name = item.getMetadata('storedName') || '';
                                            }
                                            if (!name) {
                                                name = findImageNameForKey(valueInput, key) || extractFileName(key || item.filename);
                                            }
                                            if (name && ordered.indexOf(name) === -1) {
                                                ordered.push(name);
                                            }
                                        });
                                        setImageNamesOnInput(valueInput, ordered);
                                        valueInput.trigger('change');
                                    } catch (e) {}
                                });
                            } else {
                                pond.on('removefile', function() {
                                    valueInput.val('').trigger('change');
                                    clearImageNameMap(valueInput);
                                });
                            }

                            pond.on('processfile', function(error, file) {
                                if (error) {
                                    showFilePondUploadError(normalizeFilePondErrorMessage(error, group.data('fastcrudFilePondError') || 'Upload failed.'));
                                    return;
                                }
                                if (!file) {
                                    return;
                                }
                                var key = file.serverId || file.source || '';
                                var storedName = '';
                                if (file.getMetadata && typeof file.getMetadata === 'function') {
                                    storedName = file.getMetadata('storedName') || '';
                                }
                                if (!storedName) {
                                    storedName = findImageNameForKey(valueInput, key) || extractFileName(key || file.filename);
                                    if (file.setMetadata && storedName) {
                                        file.setMetadata('storedName', storedName, true);
                                    }
                                }
                                if (file.setMetadata) {
                                    var posterCandidate = key && (/^https?:\/\//i.test(key) || key.charAt(0) === '/' || /^blob:/i.test(key) || /^data:/i.test(key))
                                        ? key
                                        : (storedName ? toPublicUrl(storedName) : '');
                                    if (posterCandidate) {
                                        file.setMetadata('poster', posterCandidate, true);
                                    }
                                }
                                if (isMultipleImages && key && storedName) {
                                    mapImageNameToKey(valueInput, key, storedName);
                                }
                            });
                        } catch (e) {}
                    });
                } else if (changeType === 'file' || changeType === 'files') {
                    // Initialize FilePond for generic files (with optional multi-select, no image preview)
                    withVisibleFilePondAssets(group, function() {
                        var fileInput = group.find('#' + $.escapeSelector(fieldId + '-file'));
                        var valueInput = group.find('#' + $.escapeSelector(fieldId));
                        if (!fileInput.length || !valueInput.length || !window.FilePond) {
                            return;
                        }

                        try {
                            var isMultipleFiles = (changeType === 'files');
                            var initialFiles = [];
                            if (isMultipleFiles) {
                                var list = parseImageNameList(valueInput.val());
                                initialFiles = list.map(function(fname) {
                                    return {
                                        source: toPublicUrl(fname),
                                        options: {
                                            type: 'local',
                                            file: { name: fname, size: 0 },
                                            metadata: { storedName: fname }
                                        }
                                    };
                                });
                            } else {
                                var name = String(valueInput.val() || '').trim();
                                if (name) {
                                    initialFiles = [{
                                        source: toPublicUrl(name),
                                        options: {
                                            type: 'local',
                                            file: { name: name, size: 0 },
                                            metadata: { storedName: name }
                                        }
                                    }];
                                }
                            }

                            var showFilePondUploadError = function(message) {
                                setFilePondFieldError(fileInput, valueInput, message);
                            };
                            var clearFilePondUploadError = function() {
                                setFilePondFieldError(fileInput, valueInput, '');
                            };
                            var uploadMaxSize = resolveUploadMaxSize(params, false);

                            var fileIdleLabel = isMultipleFiles
                                ? 'Drop files here or <span class="filepond--label-action">Browse</span>'
                                : 'Drop a file here or <span class="filepond--label-action">Browse</span>';
                            var pond = window.FilePond.create(fileInput.get(0), {
                                allowMultiple: isMultipleFiles,
                                allowReorder: isMultipleFiles,
                                allowImagePreview: false,
                                allowFilePoster: false,
                                labelIdle: fileIdleLabel,
                                labelFileLoading: 'Loading',
                                labelFileProcessing: 'Uploading',
                                labelFileProcessingComplete: 'Uploaded',
                                labelFileProcessingAborted: 'Upload cancelled',
                                labelFileProcessingError: 'Upload failed',
                                labelTapToCancel: 'tap to cancel',
                                labelTapToRetry: 'tap to retry',
                                labelTapToUndo: 'tap to remove',
                                labelButtonRemoveItem: 'Remove',
                                credits: false,
                                files: initialFiles,
                                server: {
                                    process: function(fieldName, file, metadata, load, error, progress, abort) {
                                        if (uploadMaxSize && file && typeof file.size === 'number' && file.size > uploadMaxSize.size) {
                                            var sizeMessage = buildUploadTooLargeMessage(false, uploadMaxSize);
                                            showFilePondUploadError(sizeMessage);
                                            error(sizeMessage);
                                            return { abort: function() { abort(); } };
                                        }
                                        var xhr = new XMLHttpRequest();
                                        xhr.open('POST', window.location.pathname);
                                        xhr.withCredentials = true;
                                        xhr.upload.onprogress = function(e) { progress(e.lengthComputable, e.loaded, e.total); };
                                        xhr.onload = function() {
                                            if (xhr.status < 200 || xhr.status >= 300) {
                                                var statusMessage = 'Upload failed with status ' + xhr.status;
                                                showFilePondUploadError(statusMessage);
                                                error(statusMessage);
                                                return;
                                            }
                                            var response; var raw = xhr.responseText || '';
                                            try { response = JSON.parse(raw || '{}'); } catch (e) {
                                                showFilePondUploadError('Upload returned invalid JSON.');
                                                error('Upload returned invalid JSON.');
                                                return;
                                            }
                                            if (!response || response.success !== true || !response.location) {
                                                var responseMessage = response && response.error ? response.error : 'Upload failed.';
                                                showFilePondUploadError(responseMessage);
                                                error(responseMessage);
                                                return;
                                            }
                                            clearFilePondUploadError();

                                            var storedName = '';
                                            if (response.name) { storedName = String(response.name); }
                                            if (!storedName && response.location) { storedName = extractFileName(response.location); }
                                            if (!storedName && file && file.name) { storedName = extractFileName(file.name); }

                                            if (storedName) {
                                                if (isMultipleFiles) { addImageNameToInput(valueInput, storedName); }
                                                else { valueInput.val(normalizeStoredImageName(storedName)); }
                                                valueInput.trigger('change');
                                            }
                                            var serverKey = response.location ? String(response.location) : storedName;
                                            if (isMultipleFiles && serverKey) { mapImageNameToKey(valueInput, serverKey, storedName); }
                                            load(serverKey || storedName || '');
                                        };
                                        xhr.onerror = function() {
                                            showFilePondUploadError('Upload failed due to a network error.');
                                            error('Upload failed due to a network error.');
                                        };
                                        var formData = new FormData();
                                        formData.append('file', file, file.name);
                                        formData.append('fastcrud_ajax', '1');
                                        formData.append('action', 'upload_filepond');
                                        formData.append('kind', 'file');
                                        if (tableName) { formData.append('table', tableName); }
                                        if (tableId) { formData.append('id', tableId); }
                                        formData.append('column', saveColumn);
                                        appendCrudConfigToFormData(formData, false);
                                        xhr.send(formData);
                                        return { abort: function() { xhr.abort(); abort(); } };
                                    },
                                    fetch: function(url, load, error, progress, abort) {
                                        try {
                                            var target = url;
                                            if (target && typeof target === 'string' && !/^https?:\/\//i.test(target) && target.charAt(0) !== '/' && !/^blob:/i.test(target) && !/^data:/i.test(target)) {
                                                target = toPublicUrl(target);
                                            }
                                            var xhr = new XMLHttpRequest();
                                            xhr.open('GET', target);
                                            xhr.responseType = 'blob';
                                            xhr.onload = function() { if (xhr.status >= 200 && xhr.status < 300) { load(xhr.response); } else { error('Failed to fetch file'); } };
                                            xhr.onerror = function() { error('Network error while fetching file'); };
                                            xhr.send();
                                            return { abort: function() { try { xhr.abort(); } catch (e) {} abort(); } };
                                        } catch (e) { error('Failed to fetch file'); }
                                    }
                                }
                            });
                            hydrateFilePondInitialSizes(pond, initialFiles);
                            try {
                                $(pond.element)
                                    .addClass('fastcrud-filepond-uploader')
                                    .addClass(isMultipleFiles ? 'fastcrud-filepond--multi-file' : 'fastcrud-filepond--file');
                            } catch (e) {}
                            pond.on('addfile', function() {
                                clearFilePondUploadError();
                            });
                            pond.on('removefile', function() {
                                clearFilePondUploadError();
                            });

                            if (isMultipleFiles) {
                                pond.on('removefile', function(error, file) {
                                    if (!file) { return; }
                                    var key = file.serverId || file.source || '';
                                    var storedName = '';
                                    if (file.getMetadata && typeof file.getMetadata === 'function') {
                                        storedName = file.getMetadata('storedName') || '';
                                    }
                                    if (!storedName) {
                                        storedName = findImageNameForKey(valueInput, key) || extractFileName(key || file.filename);
                                    }
                                    if (storedName) { removeImageNameFromInput(valueInput, storedName); valueInput.trigger('change'); }
                                    if (key) { removeImageNameForKey(valueInput, key); }
                                });
                                pond.on('reorderfiles', function(files) {
                                    try {
                                        var ordered = [];
                                        (files || pond.getFiles() || []).forEach(function(item) {
                                            if (!item) return;
                                            var key = item.serverId || item.source || '';
                                            var name = '';
                                            if (item.getMetadata && typeof item.getMetadata === 'function') {
                                                name = item.getMetadata('storedName') || '';
                                            }
                                            if (!name) { name = findImageNameForKey(valueInput, key) || extractFileName(key || item.filename); }
                                            if (name && ordered.indexOf(name) === -1) { ordered.push(name); }
                                        });
                                        setImageNamesOnInput(valueInput, ordered);
                                        valueInput.trigger('change');
                                    } catch (e) {}
                                });
                            } else {
                                pond.on('removefile', function() { valueInput.val('').trigger('change'); });
                            }

                            pond.on('processfile', function(error, file) {
                                if (error) {
                                    showFilePondUploadError(normalizeFilePondErrorMessage(error, group.data('fastcrudFilePondError') || 'Upload failed.'));
                                    return;
                                }
                                if (!file) { return; }
                                var key = file.serverId || file.source || '';
                                var storedName = '';
                                if (file.getMetadata && typeof file.getMetadata === 'function') { storedName = file.getMetadata('storedName') || ''; }
                                if (!storedName) {
                                    storedName = findImageNameForKey(valueInput, key) || extractFileName(key || (file.filename || ''));
                                    if (file.setMetadata && storedName) { file.setMetadata('storedName', storedName, true); }
                                }
                                if (isMultipleFiles && key && storedName) { mapImageNameToKey(valueInput, key, storedName); }
                                if (storedName) {
                                    if (isMultipleFiles) { addImageNameToInput(valueInput, storedName); }
                                    else { valueInput.val(normalizeStoredImageName(storedName)); }
                                    valueInput.trigger('change');
                                }
                            });
                        } catch (e) {}
                    });
                }
            });

            if (useTabs) {
                var availableTabs = Object.keys(tabEntries);
                var activeTab = defaultTabName && tabEntries[defaultTabName] ? defaultTabName : (availableTabs[0] || null);
                if (activeTab) {
                    Object.keys(tabEntries).forEach(function(name) {
                        var entry = tabEntries[name];
                        if (!entry) {
                            return;
                        }

                        if (name === activeTab) {
                            entry.nav.addClass('active').attr('aria-selected', 'true');
                            entry.pane.addClass('show active');
                        } else {
                            entry.nav.removeClass('active').attr('aria-selected', 'false');
                            entry.pane.removeClass('show active');
                        }
                    });
                }
            }

            initializeRichEditors(editFieldsContainer);
            initializeSelect2(editFieldsContainer);

            var behaviourSources = [formConfig.behaviours.pass_var || {}];
            if (isCreateMode && formConfig.behaviours.pass_default) {
                behaviourSources.push(formConfig.behaviours.pass_default);
            }
            var createdHiddenFields = {};
            behaviourSources.forEach(function(source) {
                Object.keys(source).forEach(function(fieldName) {
                    if (fieldName === rowPrimaryKeyColumn) {
                        return;
                    }

                    if (visibleFields.some(function(field) { return field.name === fieldName; })) {
                        return;
                    }

                    if (createdHiddenFields[fieldName]) {
                        return;
                    }

                    var behaviours = resolveBehavioursForField(fieldName, formMode);
                    var value = '';
                    if (behaviours.pass_var) {
                        value = interpolateTemplate(behaviours.pass_var, templateContext);
                    } else if (isCreateMode && behaviours.pass_default) {
                        value = interpolateTemplate(behaviours.pass_default, templateContext);
                    }

                    var hiddenId = editFormId + '-' + fieldName;
                    var hiddenField = $('<input type="hidden" />')
                        .attr('id', hiddenId)
                        .attr('data-fastcrud-field', fieldName)
                        .attr('data-fastcrud-type', 'hidden')
                        .val(value);
                    editForm.append(hiddenField);
                    templateContext[fieldName] = value;
                    createdHiddenFields[fieldName] = true;
                });
            });

            applyFieldErrors(currentFieldErrors);

            var offcanvas = getEditOffcanvasInstance();
            if (offcanvas) {
                offcanvas.show();
                scheduleSidePanelHeightSync();
            }
        }

        function showViewPanel(row) {
            if (editOffcanvasInstance) {
                editOffcanvasInstance.hide();
            }

            var offcanvas = getViewOffcanvasInstance();
            if (!offcanvas) {
                return;
            }

            var viewSectionRegistry = viewContentContainer.data('fastcrud-view-section-keys');
            if (Array.isArray(viewSectionRegistry)) {
                viewSectionRegistry.forEach(function(key) {
                    viewContentContainer.removeData(key);
                });
            }
            viewContentContainer.removeData('fastcrud-view-section-keys');

            viewContentContainer.empty();
            viewEmptyNotice.addClass('d-none').text(t('no_record_selected', 'No record selected.'));

            if (!row || $.isEmptyObject(row)) {
                viewEmptyNotice.removeClass('d-none');
                offcanvas.show();
                return;
            }

            if (!columnsCache || columnsCache.length === 0) {
                viewEmptyNotice.text('Column metadata unavailable.').removeClass('d-none');
                offcanvas.show();
                return;
            }

            var viewPrimaryKeyColumn = row.__fastcrud_primary_key || primaryKeyColumn;
            if (!primaryKeyColumn && viewPrimaryKeyColumn) {
                primaryKeyColumn = viewPrimaryKeyColumn;
            }

            if (viewHeading.length) {
                var headingText = t('view_record', 'View Record');
                var primaryValue;
                if (Object.prototype.hasOwnProperty.call(row, '__fastcrud_primary_value') && typeof row.__fastcrud_primary_value !== 'undefined') {
                    primaryValue = row.__fastcrud_primary_value;
                } else if (viewPrimaryKeyColumn && typeof row[viewPrimaryKeyColumn] !== 'undefined') {
                    primaryValue = row[viewPrimaryKeyColumn];
                }

                if (typeof primaryValue !== 'undefined' && primaryValue !== null && String(primaryValue).length > 0) {
                    headingText += ' ' + primaryValue;
                }
                viewHeading.text(headingText);
            }

            var customFieldHtml = row.__fastcrud_field_html && typeof row.__fastcrud_field_html === 'object'
                ? row.__fastcrud_field_html
                : {};

            var viewLayout = buildFormLayout('view');
            var viewSections = Array.isArray(viewLayout.sections) ? viewLayout.sections.slice() : [];
            var viewSectionMetaMap = {};
            viewSections.forEach(function(section) {
                if (!section || typeof section !== 'object' || !section.id) {
                    return;
                }
                viewSectionMetaMap[section.id] = section;
            });
            var VIEW_UNSECTIONED_ID = '__fastcrud_unsectioned__';
            var viewHasSections = viewSections.length > 0;
            var viewFields = viewLayout.fields.length
                ? viewLayout.fields.slice()
                : columnsCache.map(function(column) {
                    return { name: column, tab: null, section: null };
                });

            var viewVisibleFields = [];
            viewFields.forEach(function(field) {
                if (field.name === viewPrimaryKeyColumn) {
                    return;
                }
                if (!isFieldVisibleForRow(field.name, 'view', row)) {
                    return;
                }
                viewVisibleFields.push(field);
            });

            viewContentContainer.removeClass('list-group list-group-flush');

            var viewUsesTabs = Array.isArray(viewLayout.tabs) && viewLayout.tabs.length > 0 && viewVisibleFields.some(function(field) {
                return !!field.tab;
            });
            var viewTabsNav = null;
            var viewTabsContent = null;
            var viewTabEntries = {};
            var viewDefaultTab = viewLayout.defaultTab || null;
            var viewTabBaseId = tableId + '-view';

            if (viewUsesTabs) {
                viewTabsNav = $('<ul class="nav nav-tabs mb-3" role="tablist"></ul>');
                viewTabsContent = $('<div class="tab-content pe-1"></div>');
                viewContentContainer.append(viewTabsNav).append(viewTabsContent);
            } else if (!viewHasSections) {
                viewContentContainer.addClass('list-group list-group-flush');
            } else {
                viewContentContainer.addClass('fastcrud-view-section-container');
            }

            function ensureViewTab(tabName) {
                if (!viewTabsNav || !viewTabsContent) {
                    return null;
                }

                if (!tabName) {
                    return null;
                }

                if (viewTabEntries[tabName]) {
                    return viewTabEntries[tabName];
                }

                var slug = makeSlug(tabName);
                var tabId = viewTabBaseId + '-tab-' + slug;
                var navItem = $('<li class="nav-item" role="presentation"></li>');
                var navButton = $('<button class="nav-link" data-bs-toggle="tab" type="button" role="tab"></button>')
                    .attr('id', tabId + '-tab')
                    .attr('data-bs-target', '#' + tabId)
                    .attr('aria-controls', tabId)
                    .attr('aria-selected', 'false')
                    .text(tabName);
                navItem.append(navButton);
                viewTabsNav.append(navItem);

                var pane = $('<div class="tab-pane fade p-1" role="tabpanel"></div>')
                    .attr('id', tabId)
                    .attr('aria-labelledby', tabId + '-tab');
                var paneContainer = viewHasSections
                    ? $('<div class="fastcrud-view-section-container"></div>')
                    : $('<div class="list-group list-group-flush"></div>');
                pane.append(paneContainer);
                viewTabsContent.append(pane);

                viewTabEntries[tabName] = { nav: navButton, pane: pane, container: paneContainer, list: paneContainer };
                return viewTabEntries[tabName];
            }

            function getViewSectionMeta(sectionId) {
                if (!sectionId) {
                    return null;
                }
                if (Object.prototype.hasOwnProperty.call(viewSectionMetaMap, sectionId)) {
                    return viewSectionMetaMap[sectionId];
                }

                var fallback = {
                    id: sectionId,
                    title: sectionId === VIEW_UNSECTIONED_ID ? null : makeLabel(sectionId),
                    description: null,
                    fields: [],
                    collapsible: false,
                    collapsed: false,
                    icon: null,
                    class: null,
                    title_class: null
                };
                viewSectionMetaMap[sectionId] = fallback;
                return fallback;
            }

            function ensureViewSectionContainer(parentContainer, sectionId) {
                if (!parentContainer || !parentContainer.length) {
                    return parentContainer;
                }

                var effectiveSection = sectionId;
                if (!effectiveSection && viewHasSections) {
                    effectiveSection = VIEW_UNSECTIONED_ID;
                }

                if (!effectiveSection) {
                    return parentContainer;
                }

                var dataKey = 'fastcrud-view-section-' + effectiveSection;
                var cached = parentContainer.data(dataKey);
                if (cached && cached.list && cached.list.length) {
                    if (cached.wrapper && cached.wrapper.length
                        && parentContainer.length
                        && parentContainer[0]
                        && $.contains(parentContainer[0], cached.wrapper[0])) {
                        return cached.list;
                    }

                    parentContainer.removeData(dataKey);
                    var viewRegistry = parentContainer.data('fastcrud-view-section-keys');
                    if (Array.isArray(viewRegistry)) {
                        var idx = viewRegistry.indexOf(dataKey);
                        if (idx !== -1) {
                            viewRegistry.splice(idx, 1);
                            parentContainer.data('fastcrud-view-section-keys', viewRegistry);
                        }
                    }
                }

                var meta = getViewSectionMeta(effectiveSection) || { id: effectiveSection };
                var title = typeof meta.title === 'string' && meta.title.length
                    ? meta.title
                    : (meta.title === null ? null : makeLabel(effectiveSection));
                var description = typeof meta.description === 'string' && meta.description.length ? meta.description : null;
                var collapsible = !!meta.collapsible;
                var collapsed = collapsible && !!meta.collapsed;
                var iconClass = typeof meta.icon === 'string' && meta.icon.length ? meta.icon : null;
                var sectionClass = typeof meta.class === 'string' && meta.class.length ? meta.class : null;
                var titleClass = typeof meta.title_class === 'string' && meta.title_class.length ? meta.title_class : null;

                var wrapper = $('<div class="fastcrud-view-section mb-4"></div>')
                    .attr('data-fastcrud-section', effectiveSection);

                if (sectionClass) {
                    wrapper.addClass(sectionClass);
                }

                var header = null;
                if (title || description || collapsible) {
                    header = $('<div class="d-flex align-items-start justify-content-between mb-2 fastcrud-view-section-header"></div>');
                    var headingGroup = $('<div class="fastcrud-view-section-heading"></div>');
                    if (titleClass) {
                        headingGroup.addClass(titleClass);
                    }

                    var hasHeadingContent = false;
                    if (title) {
                        var heading = $('<h5 class="fastcrud-view-section-title text-uppercase text-muted small d-flex align-items-center mb-0"></h5>');
                        if (iconClass) {
                            heading.append($('<i class="fastcrud-form-section-icon me-1"></i>').addClass(iconClass));
                        }
                        heading.append($('<span></span>').text(title));
                        headingGroup.append(heading);
                        hasHeadingContent = true;
                    } else if (iconClass && !title) {
                        headingGroup.append($('<i class="fastcrud-form-section-icon"></i>').addClass(iconClass));
                        hasHeadingContent = true;
                    }

                    if (description) {
                        headingGroup.append($('<div class="fastcrud-view-section-description text-muted small"></div>').text(description));
                        hasHeadingContent = true;
                    }

                    if (hasHeadingContent) {
                        header.append(headingGroup);
                    }

                    wrapper.append(header);
                }

                var list = $('<div class="list-group list-group-flush"></div>');
                wrapper.append(list);

                if (collapsible && header) {
                    var toggle = $('<button type="button" class="btn btn-sm btn-outline-secondary fastcrud-section-toggle" aria-expanded="true"></button>')
                        .html(collapsed ? actionIcons.expand : actionIcons.collapse)
                        .attr('aria-expanded', collapsed ? 'false' : 'true')
                        .attr('aria-label', collapsed ? 'Expand section' : 'Collapse section');
                    header.append(toggle);

                    if (collapsed) {
                        list.addClass('d-none');
                        wrapper.addClass('fastcrud-view-section-collapsed');
                        toggle.attr('aria-expanded', 'false');
                    }

                    toggle.on('click', function() {
                        var isCollapsed = list.hasClass('d-none');
                        if (isCollapsed) {
                            list.removeClass('d-none');
                            wrapper.removeClass('fastcrud-view-section-collapsed');
                            toggle.attr('aria-expanded', 'true')
                                .attr('aria-label', 'Collapse section')
                                .html(actionIcons.collapse);
                        } else {
                            list.addClass('d-none');
                            wrapper.addClass('fastcrud-view-section-collapsed');
                            toggle.attr('aria-expanded', 'false')
                                .attr('aria-label', 'Expand section')
                                .html(actionIcons.expand);
                        }
                    });
                }

                parentContainer.append(wrapper);
                parentContainer.data(dataKey, { wrapper: wrapper, list: list });

                var viewRegistry = parentContainer.data('fastcrud-view-section-keys');
                if (!Array.isArray(viewRegistry)) {
                    viewRegistry = [];
                }
                if (viewRegistry.indexOf(dataKey) === -1) {
                    viewRegistry.push(dataKey);
                    parentContainer.data('fastcrud-view-section-keys', viewRegistry);
                }

                return list;
            }

            var viewHasContent = false;
            viewVisibleFields.forEach(function(field) {
                var column = field.name;
                var container = viewContentContainer;

                if (viewUsesTabs) {
                    var targetTab = field.tab || viewDefaultTab || (viewLayout.tabs.length ? viewLayout.tabs[0] : null);
                    if (targetTab) {
                        var entry = ensureViewTab(targetTab);
                        if (entry && entry.list) {
                            container = entry.list;
                        } else if (entry && entry.pane) {
                            container = entry.pane;
                        }
                    }
                }

                var sectionId = field.section || null;
                var targetContainer = ensureViewSectionContainer(container, sectionId);

                var label = resolveFieldLabel(column);
                var value = row[column];
                if (typeof value === 'undefined' || value === null) {
                    value = '';
                }

                if (typeof value === 'object') {
                    try {
                        value = JSON.stringify(value);
                    } catch (serializationError) {
                        value = String(value);
                    }
                }

                var displayValue = String(value);
                if (displayValue.length === 0) {
                    displayValue = 'N/A';
                }

                var item = $('<div class="list-group-item"></div>');
                item.append($('<div class="fw-semibold text-muted mb-1"></div>').text(label));

                var valueElem = $('<div class="text-break"></div>');
                if (typeof customFieldHtml[column] !== 'undefined') {
                    var viewHtml = customFieldHtml[column];
                    if (viewHtml && typeof viewHtml === 'object' && viewHtml.jquery) {
                        valueElem.append(viewHtml);
                    } else if (typeof viewHtml === 'string') {
                        if (viewHtml.indexOf('<') !== -1) {
                            valueElem.append(viewHtml);
                        } else {
                            valueElem.text(viewHtml);
                        }
                    } else {
                        valueElem.append(viewHtml);
                    }
                    item.append(valueElem);
                    targetContainer.append(item);
                    viewHasContent = true;
                    return;
                }

                try {
                    var viewBehaviours = resolveBehavioursForField(column, 'view');
                    var changeMeta = (viewBehaviours && viewBehaviours.change_type) ? viewBehaviours.change_type : {};
                    var changeType = String((changeMeta && changeMeta.type) || '').toLowerCase();
                    if (changeType === 'file') {
                        var name = String(value || '').trim();
                        if (name) {
                            var href = toPublicUrl(name);
                            var link = $('<a></a>')
                                .attr('href', href)
                                .attr('target', '_blank')
                                .attr('rel', 'noopener noreferrer')
                                .text(displayValue);
                            valueElem.empty().append(link);
                        } else {
                            valueElem.text('N/A');
                        }
                    } else if (changeType === 'files') {
                        var filesList = parseImageNameList(value);
                        if (filesList && filesList.length) {
                            var listContainer = $('<div></div>');
                            filesList.forEach(function(item) {
                                var url = toPublicUrl(item);
                                var link = $('<a class="d-block mb-1"></a>')
                                    .attr('href', url)
                                    .attr('target', '_blank')
                                    .attr('rel', 'noopener noreferrer')
                                    .text(item);
                                listContainer.append(link);
                            });
                            valueElem.empty().append(listContainer);
                        } else {
                            valueElem.text('N/A');
                        }
                    } else if (changeType === 'image' || changeType === 'images') {
                        var list = parseImageNameList(value);
                        if (list && list.length) {
                            var grid = $('<div class="row row-cols-2 row-cols-sm-3 row-cols-lg-5 g-2"></div>');
                            list.forEach(function(item) {
                                var url = toPublicUrl(item);
                                var col = $('<div class="col"></div>');
                                var link = $('<a></a>')
                                    .attr('href', url)
                                    .attr('target', '_blank')
                                    .attr('rel', 'noopener noreferrer');
                                var img = $('<img class="img-fluid img-thumbnail" />')
                                    .attr('src', url)
                                    .attr('alt', item);
                                link.append(img);
                                col.append(link);
                                grid.append(col);
                            });
                            valueElem.empty().append(grid);
                        } else {
                            valueElem.text('N/A');
                        }
                    } else if (changeType === 'color') {
                        var c = String(value || '').trim();
                        if (!c) { c = '#000000'; }
                        var swatch = $('<span></span>')
                            .css({ display: 'inline-block', width: '14px', height: '14px', verticalAlign: 'middle', border: '1px solid rgba(0,0,0,.2)', backgroundColor: c });
                        valueElem.empty().append(swatch).append(' ').append(document.createTextNode(String(displayValue)));
                    } else if (changeType === 'json') {
                        var txt = String(value || '').trim();
                        if (!txt) {
                            valueElem.text('N/A');
                        } else {
                            try { txt = JSON.stringify(JSON.parse(txt), null, 2); } catch (e) {}
                            var pre = $('<pre class="mb-0 text-break"></pre>').text(txt);
                            valueElem.empty().append(pre);
                        }
                    } else {
                        valueElem.text(displayValue);
                    }
                } catch (e) {
                    valueElem.text(displayValue);
                }
                item.append(valueElem);
                targetContainer.append(item);
                viewHasContent = true;
            });

            if (viewUsesTabs) {
                var availableViewTabs = Object.keys(viewTabEntries);
                var activeViewTab = viewDefaultTab && viewTabEntries[viewDefaultTab]
                    ? viewDefaultTab
                    : (availableViewTabs[0] || null);
                if (activeViewTab) {
                    Object.keys(viewTabEntries).forEach(function(name) {
                        var entry = viewTabEntries[name];
                        if (!entry) {
                            return;
                        }

                        if (name === activeViewTab) {
                            entry.nav.addClass('active').attr('aria-selected', 'true');
                            entry.pane.addClass('show active');
                        } else {
                            entry.nav.removeClass('active').attr('aria-selected', 'false');
                            entry.pane.removeClass('show active');
                        }
                    });
                }
            }

            if (!viewHasContent) {
                viewEmptyNotice.text('No fields available for this record.').removeClass('d-none');
            }

            offcanvas.show();
        }

        function submitEditForm(event) {
            event.preventDefault();
            event.stopPropagation();

            var formMode = String(editForm.data('mode') || 'edit');
            var isCreateMode = formMode === 'create';
            var primaryColumn = editForm.data('primaryKeyColumn');
            var primaryValue = editForm.data('primaryKeyValue');

            if (!primaryColumn) {
                showFormError('Primary key column missing.');
                return false;
            }

            if (!isCreateMode && (primaryValue === null || typeof primaryValue === 'undefined' || String(primaryValue).length === 0)) {
                showFormError('Primary key value missing.');
                return false;
            }

            clearFormAlerts();
            currentFieldErrors = {};

            if (window.tinymce && typeof window.tinymce.triggerSave === 'function') {
                window.tinymce.triggerSave();
            }

            var submitButtons = editOffcanvasElement.find('button[type="submit"]');
            var originalTexts = [];
            var submitBusyText = isCreateMode ? 'Creating...' : 'Saving...';
            submitButtons.each(function(index, button) {
                var buttonEl = jQuery(button);
                originalTexts[index] = buttonEl.text();
                buttonEl.prop('disabled', true).text(submitBusyText);
            });

            var submitAction = lastSubmitAction || 'close';
            if (!isCreateMode && submitAction === 'new') {
                submitAction = 'close';
            }

            function restoreSubmitButtons() {
                submitButtons.each(function(index, button) {
                    var buttonEl = jQuery(button);
                    var action = buttonEl.data('fastcrudSubmitAction') || 'close';
                    var fallbackText;
                    if (action === 'new' && isCreateMode) {
                        fallbackText = 'Create Record & New';
                    } else if (isCreateMode) {
                        fallbackText = 'Create Record & Close';
                    } else {
                        fallbackText = 'Save Changes';
                    }

                    var originalText = typeof originalTexts[index] !== 'undefined'
                        ? originalTexts[index]
                        : fallbackText;
                    buttonEl.prop('disabled', false).text(originalText);
                });
            }

            // Ensure FilePond uploads (if any) finish before collecting values
            function waitForFilePondUploads() {
                return new Promise(function(resolve, reject) {
                    if (!window.FilePond || typeof window.FilePond.find !== 'function') {
                        resolve();
                        return;
                    }
                    var inputs = editForm.find('input.fastcrud-filepond').toArray();
                    var ponds = window.FilePond.find(inputs);
                    if (!ponds || !ponds.length) {
                        resolve();
                        return;
                    }
                    var tasks = [];
                    ponds.forEach(function(pond) {
                        try {
                            tasks.push(pond.processFiles());
                        } catch (e) {}
                    });
                    if (!tasks.length) {
                        resolve();
                        return;
                    }
                    Promise.all(tasks).then(function() { resolve(); }).catch(function(error) { reject(error); });
                });
            }

            function collectAndSubmit() {
                var fields = {};
                var fieldErrors = {};
                var validationPassed = true;

                editForm.find('[data-fastcrud-field]').each(function() {
                    var input = $(this);
                    var column = input.data('fastcrudField');
                    if (!column) {
                        return;
                    }
                    if (column === primaryColumn) {
                        return;
                    }

                    if (input.prop('disabled')) {
                        return;
                    }

                    var type = String(input.data('fastcrudType') || input.attr('data-fastcrud-type') || 'text').toLowerCase();
                    var rawValue;
                    var valueForField = null;
                    var lengthForValidation = 0;
                    var attachedControls = input.data && typeof input.data === 'function'
                        ? input.data('fastcrudControls')
                        : null;

                    if (type === 'checkbox') {
                        rawValue = input.is(':checked');
                        valueForField = rawValue ? '1' : '0';
                        lengthForValidation = rawValue ? 1 : 0;
                    } else if (type === 'multiselect') {
                        rawValue = input.val() || [];
                        var trimmedValues = $.map(rawValue, function(item) {
                            if (item === null || typeof item === 'undefined') {
                                return null;
                            }
                            var normalized = String(item).trim();
                            return normalized.length ? normalized : null;
                        });
                        lengthForValidation = trimmedValues.length;
                        valueForField = trimmedValues.length ? trimmedValues.join(',') : null;
                    } else if (type === 'multicheckbox') {
                        rawValue = input.val();
                        if (rawValue === null || typeof rawValue === 'undefined' || rawValue === '') {
                            valueForField = null;
                            lengthForValidation = 0;
                        } else {
                            var checkboxValues = String(rawValue).split(',').map(function(item) {
                                return String(item).trim();
                            }).filter(function(item) { return item.length > 0; });
                            lengthForValidation = checkboxValues.length;
                            valueForField = checkboxValues.length ? checkboxValues.join(',') : null;
                        }
                    } else if (type === 'json') {
                        rawValue = input.val();
                        if (rawValue === null || typeof rawValue === 'undefined') {
                            valueForField = null;
                            lengthForValidation = 0;
                            clearInlineFieldError(input);
                        } else {
                            var jsonCandidate = String(rawValue).trim();
                            if (jsonCandidate === '') {
                                valueForField = null;
                                lengthForValidation = 0;
                                clearInlineFieldError(input);
                            } else {
                                try {
                                    JSON.parse(jsonCandidate);
                                    valueForField = jsonCandidate; // keep user formatting
                                    lengthForValidation = jsonCandidate.length;
                                    clearInlineFieldError(input);
                                } catch (e) {
                                    validationPassed = false;
                                    var message = buildJsonErrorMessage(e);
                                    fieldErrors[column] = message;
                                    setInlineFieldError(input, message);
                                    valueForField = jsonCandidate;
                                    lengthForValidation = jsonCandidate.length;
                                }
                            }
                        }
                    } else if (type === 'password') {
                        rawValue = input.val();
                        var hasExistingPassword = String(input.attr('data-fastcrud-password-existing') || '').trim() === '1';
                        if (rawValue === null || typeof rawValue === 'undefined') {
                            valueForField = null;
                            lengthForValidation = hasExistingPassword ? Number.MAX_SAFE_INTEGER : 0;
                        } else {
                            var passwordCandidate = String(rawValue).trim();
                            if (passwordCandidate === '') {
                                valueForField = null;
                                lengthForValidation = hasExistingPassword ? Number.MAX_SAFE_INTEGER : 0;
                            } else {
                                valueForField = passwordCandidate;
                                lengthForValidation = passwordCandidate.length;
                            }
                        }
                    } else {
                        rawValue = input.val();
                        if (rawValue === null || typeof rawValue === 'undefined') {
                            valueForField = null;
                            lengthForValidation = 0;
                        } else {
                            var normalizedValue = String(rawValue).trim();
                            if (normalizedValue === '') {
                                valueForField = null;
                                lengthForValidation = 0;
                            } else {
                                valueForField = normalizedValue;
                                lengthForValidation = normalizedValue.length;
                            }
                        }
                    }

                    var requiredMin = parseInt(input.attr('data-fastcrud-required') || '', 10);
                    if (!Number.isNaN(requiredMin) && requiredMin > 0) {
                        if (lengthForValidation < requiredMin) {
                            validationPassed = false;
                            fieldErrors[column] = 'This field is required.';
                            input.addClass('is-invalid');
                            if (attachedControls && attachedControls.length) {
                                attachedControls.addClass('is-invalid');
                            }
                        }
                    }

                    var maxLength = parseInt(input.attr('data-fastcrud-max-length') || '', 10);
                    var maxLengthValidationCount = lengthForValidation;
                    if (input.is('input, textarea')) {
                        maxLengthValidationCount = String(input.val() || '').length;
                    }
                    if (!Number.isNaN(maxLength) && maxLength > 0 && maxLengthValidationCount > maxLength) {
                        validationPassed = false;
                        fieldErrors[column] = 'Must be ' + maxLength + ' characters or fewer.';
                        input.addClass('is-invalid');
                        if (attachedControls && attachedControls.length) {
                            attachedControls.addClass('is-invalid');
                        }
                    }

                    var patternRaw = input.attr('data-fastcrud-pattern');
                    if (patternRaw && valueForField !== null && valueForField !== '' && type !== 'multiselect') {
                        var regex = compileClientPattern(patternRaw);
                        if (regex && !regex.test(String(valueForField))) {
                            validationPassed = false;
                            fieldErrors[column] = 'Value does not match the expected format.';
                            input.addClass('is-invalid');
                            if (attachedControls && attachedControls.length) {
                                attachedControls.addClass('is-invalid');
                            }
                        }
                    }

                    fields[column] = valueForField;
                });

                if (!validationPassed) {
                    currentFieldErrors = fieldErrors;
                    applyFieldErrors(fieldErrors);
                    showFormError('Please fix the highlighted fields.');
                    restoreSubmitButtons();
                    return false;
                }

                var offcanvas = getEditOffcanvasInstance();
                var shouldHideOffcanvas = true;
                if (formOnlyMode) {
                    if (isCreateMode) {
                        shouldHideOffcanvas = submitAction !== 'new';
                    } else {
                        shouldHideOffcanvas = false;
                    }
                }
                if (offcanvas && shouldHideOffcanvas) {
                    offcanvas.hide();
                }

                var requestData = {
                    fastcrud_ajax: '1',
                    action: isCreateMode ? 'create' : 'update',
                    table: tableName,
                    id: tableId,
                    fields: JSON.stringify(fields)
                };
                attachCrudConfig(requestData, false);
                if (!isCreateMode) {
                    requestData.primary_key_column = primaryColumn;
                    requestData.primary_key_value = primaryValue;
                }

                $.ajax({
                    url: window.location.pathname,
                    type: 'POST',
                    dataType: 'json',
                    data: requestData,
                    success: function(response) {
                        if (response && response.success) {
                            if (isCreateMode) {
                                editSuccess.text('Record created successfully.');
                            } else {
                                editSuccess.text('Changes saved successfully.');
                            }
                            if (formOnlyMode && !isCreateMode) {
                                editSuccess.removeClass('d-none');
                            } else {
                                editSuccess.addClass('d-none');
                            }
                            currentFieldErrors = {};
                            if (!isCreateMode) {
                                try {
                                    var key = rowCacheKey(primaryColumn, String(primaryValue));
                                    if (response.row) {
                                        rowCache[key] = response.row;
                                    } else if (rowCache[key]) {
                                        delete rowCache[key];
                                    }
                                } catch (e) {}
                            }
                            loadTableData(currentPage);
                            if (isCreateMode && submitAction === 'new') {
                                var resolvedPrimaryForNew = resolvePrimaryKeyColumn();
                                if (!resolvedPrimaryForNew) {
                                    showFormError('Unable to prepare a new form instance.');
                                } else {
                                    var freshRow = getFormTemplate('create');
                                    if (!freshRow) {
                                        freshRow = {
                                            __fastcrud_primary_key: resolvedPrimaryForNew,
                                            __fastcrud_primary_value: null
                                        };
                                    } else {
                                        freshRow.__fastcrud_primary_key = resolvedPrimaryForNew || freshRow.__fastcrud_primary_key || null;
                                        freshRow.__fastcrud_primary_value = null;
                                    }
                                    showEditForm(ensureRowColumns(freshRow));
                                    editSuccess.text('Record created successfully.').removeClass('d-none');
                                }
                            }
                        } else {
                            var fallbackMessage = isCreateMode ? 'Failed to create record.' : 'Failed to update record.';
                            var message = response && response.error ? response.error : fallbackMessage;
                            if (response && response.errors) {
                                currentFieldErrors = response.errors;
                                applyFieldErrors(response.errors);
                            }
                            showFormError(message);
                            if (offcanvas) {
                                offcanvas.show();
                            }
                        }
                    },
                    error: function(_, __, error) {
                        var failureMessage = isCreateMode ? 'Failed to create record: ' + error : 'Failed to update record: ' + error;
                        showFormError(failureMessage);
                        if (offcanvas) {
                            offcanvas.show();
                        }
                    },
                    complete: function() {
                        restoreSubmitButtons();
                        lastSubmitAction = null;
                    }
                });

                return false;
            }

            waitForFilePondUploads().then(collectAndSubmit).catch(function(error) {
                var message = 'Please fix the highlighted uploads.';
                if (error && typeof error.message === 'string' && error.message.trim() !== '') {
                    message = error.message.trim();
                } else if (typeof error === 'string' && error.trim() !== '') {
                    message = error.trim();
                }
                showFormError(message);
                restoreSubmitButtons();
            });
            return false;
        }

        function getPkInfoFromElement(el) {
            var jqEl = $(el);
            var tr = jqEl.closest('tr');
            var pkCol = tr.attr('data-fastcrud-pk') || primaryKeyColumn || '';
            var pkVal = tr.attr('data-fastcrud-pk-value');
            if (!pkCol || typeof pkVal === 'undefined') { return null; }
            return { column: pkCol, value: pkVal };
        }

        function rowCacheKey(pkCol, pkVal, mode) {
            return tableId + '::' + String(pkCol) + '::' + String(pkVal) + '::' + String(mode || 'edit');
        }

        function fetchRowByPk(pkCol, pkVal, mode) {
            var normalizedMode = typeof mode === 'string' ? mode.toLowerCase() : '';
            if (['create', 'edit', 'view'].indexOf(normalizedMode) === -1) {
                normalizedMode = 'edit';
            }

            var key = rowCacheKey(pkCol, pkVal, normalizedMode);
            var cachedRow = rowCache[key];
            if (cachedRow) {
                var hasFieldHtml = cachedRow.__fastcrud_field_html && typeof cachedRow.__fastcrud_field_html === 'object'
                    && Object.keys(cachedRow.__fastcrud_field_html).length > 0;
                if (!requiresCustomFieldHtml || hasFieldHtml) {
                    return Promise.resolve(deepClone(cachedRow));
                }
            }
            return new Promise(function(resolve, reject) {
                var requestData = {
                    fastcrud_ajax: '1',
                    action: 'read',
                    table: tableName,
                    id: tableId,
                    primary_key_column: pkCol,
                    primary_key_value: pkVal,
                    render_mode: normalizedMode
                };
                attachCrudConfig(requestData, false);
                $.ajax({
                    url: window.location.pathname,
                    type: 'POST',
                    dataType: 'json',
                    data: requestData,
                    success: function(response) {
                        if (response && response.success && response.row) {
                            var row = response.row;
                            if (row && typeof row === 'object') {
                                row.__fastcrud_render_mode = normalizedMode;
                            }
                            rowCache[key] = row;
                            resolve(deepClone(row));
                        } else {
                            reject(new Error(response && response.error ? response.error : 'Record not found'));
                        }
                    },
                    error: function(_, __, error) {
                        reject(new Error(error || 'Failed to fetch record'));
                    }
                });
            });
        }

        // Inline edit core
        function startInlineEdit(td) {
            var cell = $(td);
            var column = String(cell.attr('data-fastcrud-column') || '');
            var baseKey = column.indexOf('__') !== -1 ? column.split('__').pop() : column;
            if (!column || (!inlineEditFields[column] && !inlineEditFields[baseKey])) {
                return;
            }
            if (cell.closest('td').hasClass('fastcrud-actions-cell')) { return; }
            if (cell.find('input.fastcrud-bool-view').length) { return; }
            if (cell.data('fastcrudEditing')) { return; }

            var pk = getPkInfoFromElement(cell);
            if (!pk) {
                return;
            }

            cell.data('fastcrudEditing', true);
            var originalHtml = cell.html();
            cell.empty();
            var wrapper = $('<div class="fastcrud-inline-editor"></div>');
            var input = null;

            // Try to pick a better editor based on behaviours
            var fieldKey = inlineEditFields[column] ? column : baseKey;
            var behaviours = resolveBehavioursForField(fieldKey, 'edit');
            var changeMeta = behaviours && behaviours.change_type ? behaviours.change_type : {};
            var changeType = String((changeMeta && changeMeta.type) || 'text').toLowerCase();
            var params = (changeMeta && changeMeta.params && typeof changeMeta.params === 'object') ? changeMeta.params : {};

            if (changeType === 'number') {
                input = $('<input type="number" class="form-control form-control-sm fastcrud-inline-input" />');
            } else if (changeType === 'email') {
                input = $('<input type="email" class="form-control form-control-sm fastcrud-inline-input" />');
            } else if (changeType === 'date') {
                input = $('<input type="date" class="form-control form-control-sm fastcrud-inline-input" />');
            } else if (changeType === 'datetime' || changeType === 'datetime-local') {
                input = $('<input type="datetime-local" class="form-control form-control-sm fastcrud-inline-input" />');
            } else if (changeType === 'time') {
                input = $('<input type="time" class="form-control form-control-sm fastcrud-inline-input" />');
            } else if (changeType === 'color') {
                input = $('<input type="color" class="form-control form-control-color form-control-sm fastcrud-inline-input" />');
            } else if (
                changeType === 'select' ||
                changeType === 'multiselect' ||
                changeType === 'radio' ||
                changeType === 'multicheckbox' ||
                changeType === 'multi_checkbox'
            ) {
                var inlineIsMulti = (changeType === 'multiselect' || changeType === 'multicheckbox' || changeType === 'multi_checkbox');
                input = $('<select class="form-select form-select-sm fastcrud-inline-input" ' + (inlineIsMulti ? 'multiple' : '') + '></select>');
                var optionMap = params.values || params.options || {};
                var optionsList = [];
                if ($.isArray(optionMap)) {
                    optionMap.forEach(function(optionValue) {
                        optionsList.push({ value: optionValue, label: optionValue });
                    });
                } else if (typeof optionMap === 'object') {
                    Object.keys(optionMap).forEach(function(key) {
                        optionsList.push({ value: key, label: optionMap[key] });
                    });
                }
                if (params.placeholder && !inlineIsMulti) {
                    input.append($('<option value=""></option>').text(String(params.placeholder)));
                }
                optionsList.forEach(function(option) {
                    input.append($('<option></option>').attr('value', option.value).text(option.label));
                });
            } else {
                input = $('<input type="text" class="form-control form-control-sm fastcrud-inline-input" />');
            }

            wrapper.append(input);
            cell.append(wrapper);

            if (input.is('select')) {
                initializeSelect2(wrapper);
            }

            function restore() {
                if (input && input.is && input.is('select')) {
                    destroySelect2(wrapper);
                }
                cell.data('fastcrudEditing', false);
                cell.html(originalHtml);
            }

            function ensureColor(value) {
                var s = String(value || '').trim();
                if (!s) { return '#000000'; }
                var hex6 = /^#([0-9a-fA-F]{6})$/;
                if (hex6.test(s)) { return s; }
                // accept without #
                if (/^([0-9a-fA-F]{6})$/.test(s)) { return '#' + s; }
                return '#000000';
            }

            var committing = false;
            var skipInitialCommit = true;

            fetchRowByPk(pk.column, pk.value, 'edit').then(function(row){
                if (!isFieldVisibleForRow(fieldKey, 'edit', row) || !isFieldEditableForRow(fieldKey, 'edit', row)) {
                    restore();
                    return;
                }

                var startValue = row && Object.prototype.hasOwnProperty.call(row, fieldKey) ? (row[fieldKey] == null ? '' : String(row[fieldKey])) : '';
                if (changeType === 'color') {
                    input.val(ensureColor(startValue)).focus();
                } else if (input.is('select')) {
                    if (input.prop('multiple')) {
                        var parts = String(startValue).split(',').map(function(s){ return s.trim(); }).filter(function(s){ return s.length; });
                        input.val(parts);
                    } else {
                        input.val(startValue);
                    }
                    if (input.data('select2')) {
                        input.trigger('change');
                    }
                    input.focus();
                } else {
                    input.val(startValue).focus().select();
                }
                skipInitialCommit = false;
            }).catch(function(){
                // If fetch fails, allow editing current display text
                var current = cell.text();
                if (changeType === 'color') {
                    input.val(ensureColor(current)).focus();
                } else if (input.is('select')) {
                    input.val(current).focus();
                    if (input.data('select2')) {
                        input.trigger('change');
                    }
                } else {
                    input.val(current).focus().select();
                }
                skipInitialCommit = false;
            });

            function requestCommit(force) {
                if (!force && skipInitialCommit) {
                    return;
                }
                skipInitialCommit = false;
                commit();
            }

            function commit() {
                if (committing) return;
                committing = true;
                var newValue;
                if (input.is('select') && input.prop('multiple')) {
                    var arr = input.val() || [];
                    if (!Array.isArray(arr)) { arr = [arr]; }
                    newValue = arr.join(',');
                } else {
                    newValue = String(input.val() || '');
                }
                var payload = {};
                payload[fieldKey] = newValue;
                var requestData = {
                    fastcrud_ajax: '1',
                    action: 'update',
                    table: tableName,
                    id: tableId,
                    primary_key_column: pk.column,
                    primary_key_value: pk.value,
                    fields: JSON.stringify(payload)
                };
                attachCrudConfig(requestData, false);
                $.ajax({
                    url: window.location.pathname,
                    type: 'POST',
                    dataType: 'json',
                    data: requestData,
                    success: function(response) {
                        if (response && response.success) {
                            try {
                                var key = rowCacheKey(pk.column, String(pk.value));
                                if (response.row) { rowCache[key] = response.row; } else if (rowCache[key]) { delete rowCache[key]; }
                            } catch (e) {}
                            destroySelect2(wrapper);
                            loadTableData(currentPage);
                        } else {
                            var message = response && response.error ? response.error : 'Failed to update value.';
                            window.alert(message);
                            restore();
                        }
                    },
                    error: function(_, __, error) {
                        window.alert('Failed to update value: ' + error);
                        restore();
                    }
                });
            }

            input.on('keydown', function(e){
                if (e.key === 'Enter') { e.preventDefault(); requestCommit(true); }
                else if (e.key === 'Escape') { e.preventDefault(); restore(); }
            });
            if (changeType === 'color') {
                input.on('change', function(){ requestCommit(false); });
                input.on('blur', function(){ requestCommit(false); });
            } else if (input.is('select')) {
                input.on('change', function(){ requestCommit(false); });
                if (select2Enabled) {
                    input.on('select2:close', function(){ requestCommit(false); });
                    input.on('blur', function(){
                        var element = $(this);
                        if (!element.hasClass('select2-hidden-accessible')) {
                            requestCommit(false);
                        }
                    });
                } else {
                    input.on('blur', function(){ requestCommit(false); });
                }
            } else {
                input.on('blur', function(){ requestCommit(false); });
            }
        }

        table.on('click', 'tbody td[data-fastcrud-column]', function(event) {
            // Ignore clicks on interactive elements inside the cell
            var target = $(event.target);
            if (target.is('a,button,input,select,textarea') || target.closest('.btn, .dropdown, .fastcrud-actions-cell').length) {
                return;
            }
            startInlineEdit(this);
        });

        // Allow double-click to force inline edit even if content is nested (e.g., inside <strong>)
        table.on('dblclick', 'tbody td[data-fastcrud-column]', function(event) {
            startInlineEdit(this);
            event.preventDefault();
        });

        metaContainer.on('click', '.fastcrud-add-btn', function(event) {
            event.preventDefault();
            event.stopPropagation();
            if (!primaryKeyColumn) {
                showError('Unable to determine primary key for creating records.');
                return false;
            }

            editForm.data('mode', 'create');
            clearRowHighlight();

            var templateRow = getFormTemplate('create');
            if (!templateRow) {
                templateRow = {
                    __fastcrud_primary_key: primaryKeyColumn,
                    __fastcrud_primary_value: null
                };
            } else {
                templateRow.__fastcrud_primary_key = primaryKeyColumn || templateRow.__fastcrud_primary_key || null;
                templateRow.__fastcrud_primary_value = null;
            }

            showEditForm(ensureRowColumns(templateRow));
            return false;
        });

        table.on('click', '.fastcrud-view-btn', function(event) {
            event.preventDefault();
            event.stopPropagation();
            var pk = getPkInfoFromElement(this);
            if (!pk) { showError('Unable to determine primary key for viewing.'); return false; }
            var tr = $(this).closest('tr');
            highlightRow(tr);
            fetchRowByPk(pk.column, pk.value, 'view')
                .then(function(row){
                    showViewPanel(row || {});
                    highlightRow(tr);
                })
                .catch(function(err){ showError('Failed to load record: ' + (err && err.message ? err.message : err)); });
            return false;
        });

        table.on('click', '.fastcrud-edit-btn', function(event) {
            event.preventDefault();
            event.stopPropagation();
            editForm.data('mode', 'edit');
            var tr = $(this).closest('tr');
            highlightRow(tr);
            var pk = getPkInfoFromElement(this);
            if (!pk) { showError('Unable to determine primary key for editing.'); return false; }
            fetchRowByPk(pk.column, pk.value, 'edit')
                .then(function(row){
                    showEditForm(row || {});
                    highlightRow(tr);
                })
                .catch(function(err){ showError('Failed to load record: ' + (err && err.message ? err.message : err)); });
            return false;
        });

        // Inline toggle for boolean switches in grid
        table.on('change', 'input.fastcrud-bool-view', function(event) {
            var input = $(this);
            if (input.data('fastcrudUpdating')) {
                return;
            }
            var field = String(input.attr('data-fastcrud-field') || '').trim();
            var pkCol = String(input.attr('data-fastcrud-pk') || '').trim();
            var pkVal = input.attr('data-fastcrud-pk-value');
            if (!field || !pkCol || typeof pkVal === 'undefined') {
                // revert change if metadata is missing
                input.prop('checked', !input.is(':checked'));
                return;
            }

            var newValue = input.is(':checked') ? '1' : '0';
            var wasChecked = !input.is(':checked');
            input.prop('disabled', true);
            input.data('fastcrudUpdating', true);

            var payloadFields = {};
            payloadFields[field] = newValue;
            var requestData = {
                fastcrud_ajax: '1',
                action: 'update',
                table: tableName,
                id: tableId,
                primary_key_column: pkCol,
                primary_key_value: pkVal,
                fields: JSON.stringify(payloadFields)
            };
            attachCrudConfig(requestData, false);

            $.ajax({
                url: window.location.pathname,
                type: 'POST',
                dataType: 'json',
                data: requestData,
                success: function(response) {
                    if (response && response.success) {
                        loadTableData(currentPage);
                    } else {
                        var message = response && response.error ? response.error : 'Failed to update value.';
                        if (window.console && console.error) console.error('FastCrud toggle error:', message);
                        input.prop('checked', wasChecked);
                    }
                },
                error: function(_, __, error) {
                    if (window.console && console.error) console.error('FastCrud toggle request failed:', error);
                    input.prop('checked', wasChecked);
                },
                complete: function() {
                    input.prop('disabled', false);
                    input.removeData('fastcrudUpdating');
                }
            });
        });

        function requestDelete(row) {
            if (!row) {
                showError('Unable to determine primary key for deletion.');
                return;
            }

            var rowPrimaryKeyColumn = row.__fastcrud_primary_key || primaryKeyColumn;
            if (!rowPrimaryKeyColumn) {
                showError('Unable to determine primary key for deletion.');
                return;
            }

            var primaryValue;
            if (Object.prototype.hasOwnProperty.call(row, '__fastcrud_primary_value') && typeof row.__fastcrud_primary_value !== 'undefined') {
                primaryValue = row.__fastcrud_primary_value;
            } else {
                primaryValue = row[rowPrimaryKeyColumn];
            }

            if (!primaryKeyColumn) {
                primaryKeyColumn = rowPrimaryKeyColumn;
            }

            if (typeof primaryValue === 'undefined' || primaryValue === null || String(primaryValue).length === 0) {
                showError('Missing primary key value for selected record.');
                return;
            }

            if (deleteConfirm) {
                var confirmationMessage = 'Are you sure you want to delete record ' + primaryValue + '?';
                if (!window.confirm(confirmationMessage)) {
                    return;
                }
            }

            var requestData = {
                fastcrud_ajax: '1',
                action: 'delete',
                table: tableName,
                id: tableId,
                primary_key_column: rowPrimaryKeyColumn,
                primary_key_value: primaryValue
            };
            attachCrudConfig(requestData, false);

            $.ajax({
                url: window.location.pathname,
                type: 'POST',
                dataType: 'json',
                data: requestData,
                success: function(response) {
                    if (response && response.success) {
                        loadTableData(currentPage);
                    } else {
                        var message = response && response.error ? response.error : 'Failed to delete record.';
                        showError(message);
                    }
                },
                error: function(_, __, error) {
                    showError('Failed to delete record: ' + error);
                }
            });
        }

        function requestDuplicate(row) {
            if (!row) {
                showError('Unable to determine primary key for duplication.');
                return;
            }

            var rowPrimaryKeyColumn = row.__fastcrud_primary_key || primaryKeyColumn;
            if (!rowPrimaryKeyColumn) {
                showError('Unable to determine primary key for duplication.');
                return;
            }

            var primaryValue;
            if (Object.prototype.hasOwnProperty.call(row, '__fastcrud_primary_value') && typeof row.__fastcrud_primary_value !== 'undefined') {
                primaryValue = row.__fastcrud_primary_value;
            } else {
                primaryValue = row[rowPrimaryKeyColumn];
            }

            if (!primaryKeyColumn) {
                primaryKeyColumn = rowPrimaryKeyColumn;
            }

            if (typeof primaryValue === 'undefined' || primaryValue === null || String(primaryValue).length === 0) {
                showError('Missing primary key value for selected record.');
                return;
            }

            var requestData = {
                fastcrud_ajax: '1',
                action: 'duplicate',
                table: tableName,
                id: tableId,
                primary_key_column: rowPrimaryKeyColumn,
                primary_key_value: primaryValue
            };
            attachCrudConfig(requestData, false);

            $.ajax({
                url: window.location.pathname,
                type: 'POST',
                dataType: 'json',
                data: requestData,
                success: function(response) {
                    if (response && response.success) {
                        // Trigger event with both source and new rows
                        try {
                            table.trigger('fastcrud:duplicate', {
                                tableId: tableId,
                                row: row,
                                newRow: response.row || null
                            });
                        } catch (e) {}
                        loadTableData(currentPage);
                    } else {
                        var message = response && response.error ? response.error : 'Failed to duplicate record.';
                        showError(message);
                    }
                },
                error: function(_, __, error) {
                    showError('Failed to duplicate record: ' + error);
                }
            });
        }

        function collectSelectionForBulk(showFeedback) {
            var keys = Object.keys(selectedRows);
            if (!keys.length) {
                if (showFeedback) {
                    showError('Select at least one row before applying a bulk action.');
                }
                return null;
            }

            var grouped = {};
            keys.forEach(function(key) {
                var entry = selectedRows[key];
                if (!entry || !entry.column) {
                    return;
                }

                var column = entry.column;
                if (!grouped[column]) {
                    grouped[column] = [];
                }

                grouped[column].push(entry.value);
            });

            var columns = Object.keys(grouped);
            if (!columns.length) {
                if (showFeedback) {
                    showError('No valid selections available for this bulk action.');
                }
                return null;
            }

            if (columns.length > 1) {
                if (showFeedback) {
                    showError('Bulk actions require all selections to share the same primary key column.');
                }
                return null;
            }

            var pkColumn = columns[0];
            var values = grouped[pkColumn];
            if (!values.length) {
                if (showFeedback) {
                    showError('No values available for the selected rows.');
                }
                return null;
            }

            return { column: pkColumn, values: values };
        }

        function requestBatchDelete() {
            if (!allowBatchDeleteButton) {
                showError('Bulk delete is not enabled for this table.');
                return;
            }

            var selection = collectSelectionForBulk(true);
            if (!selection) {
                return;
            }

            var pkColumn = selection.column;
            var values = selection.values;

            var confirmationMessage = values.length === 1
                ? 'Are you sure you want to delete the selected record?'
                : 'Are you sure you want to delete the ' + values.length + ' selected records?';

            if (deleteConfirm && !window.confirm(confirmationMessage)) {
                return;
            }

            var payload = {
                fastcrud_ajax: '1',
                action: 'batch_delete',
                table: tableName,
                id: tableId,
                primary_key_column: pkColumn,
                primary_key_values: values
            };
            attachCrudConfig(payload, false);
            sendBulkAjax(payload, 'Failed to delete selected records.');
        }

        function sendBulkAjax(payload, defaultMessage) {
            $.ajax({
                url: window.location.pathname,
                type: 'POST',
                dataType: 'json',
                data: payload,
                success: function(response) {
                    if (response && response.success) {
                        clearSelection();
                        loadTableData(currentPage);
                        metaContainer.find('.fastcrud-bulk-action-select').val('');
                        updateBulkActionState();
                    } else {
                        var message = response && response.error ? response.error : defaultMessage;
                        showError(message);
                    }
                },
                error: function(_, __, error) {
                    showError(defaultMessage + ' ' + error);
                }
            });
        }

        function requestBulkAction(actionKey) {
            if (!Array.isArray(bulkActions) || !bulkActions.length) {
                showError('No bulk actions are configured.');
                return;
            }

            var index = parseInt(actionKey, 10);
            if (isNaN(index) || index < 0 || index >= bulkActions.length) {
                showError('Invalid bulk action selected.');
                return;
            }

            var action = bulkActions[index] || {};
            var selection = collectSelectionForBulk(true);
            if (!selection) {
                return;
            }

            if (action.confirm && !window.confirm(String(action.confirm))) {
                return;
            }

            if (!action.fields || typeof action.fields !== 'object') {
                showError('Bulk update action is missing field assignments.');
                return;
            }

            var payload = {
                fastcrud_ajax: '1',
                action: 'bulk_update',
                table: tableName,
                id: tableId,
                primary_key_column: selection.column,
                primary_key_values: selection.values,
                fields: JSON.stringify(action.fields)
            };
            attachCrudConfig(payload, false);

            sendBulkAjax(payload, 'Failed to apply bulk update.');
        }

        function startExport() {
            var params = new URLSearchParams();
            params.set('fastcrud_ajax', '1');
            params.set('action', 'export_csv');
            params.set('table', tableName);
            params.set('id', tableId);
            appendCrudConfigToParams(params, true);

            if (primaryKeyColumn) {
                params.set('primary_key_column', primaryKeyColumn);
            }

            if (currentSearchTerm) {
                params.set('search_term', currentSearchTerm);
            }

            if (currentSearchColumn) {
                params.set('search_column', currentSearchColumn);
            }

            var selection = collectSelectionForBulk(false);
            if (selection && selection.values && selection.values.length) {
                selection.values.forEach(function(value) {
                    params.append('primary_key_values[]', value);
                });
            }

            var url = window.location.pathname + '?' + params.toString();
            window.open(url, '_blank');
        }

        table.on('change', '.fastcrud-select-row', function() {
            if (!batchDeleteEnabled) {
                $(this).prop('checked', false);
                return;
            }

            var checkbox = $(this);
            var pkCol = checkbox.attr('data-fastcrud-pk');
            var pkVal = checkbox.attr('data-fastcrud-pk-value');
            if (!pkCol || typeof pkVal === 'undefined') {
                checkbox.prop('checked', false);
                return;
            }

            var checked = checkbox.is(':checked');
            setSelection(pkCol, pkVal, checked);
            refreshSelectAllState();
            updateBatchDeleteButtonState();
        });

        table.on('click', '.fastcrud-nested-toggle', function(event) {
            event.preventDefault();
            event.stopPropagation();
            toggleNested($(this));
            return false;
        });

        metaContainer.on('click', '.fastcrud-batch-delete-btn', function(event) {
            event.preventDefault();
            event.stopPropagation();
            syncQueryBuilderToConfig();
            requestBatchDelete();
            return false;
        });

        metaContainer.on('change', '.fastcrud-bulk-action-select', function() {
            updateBulkActionState();
        });

        metaContainer.on('click', '.fastcrud-bulk-apply-btn', function(event) {
            event.preventDefault();
            event.stopPropagation();
            var select = $(this).closest('.fastcrud-bulk-actions').find('.fastcrud-bulk-action-select');
            if (!select.length) {
                showError('Select a bulk action to apply.');
                return false;
            }

            var actionIndex = select.val();
            if (!actionIndex) {
                showError('Select a bulk action to apply.');
                return false;
            }

            syncQueryBuilderToConfig();
            requestBulkAction(actionIndex);
            return false;
        });

        metaContainer.on('click', '.fastcrud-export-csv-btn', function(event) {
            event.preventDefault();
            event.stopPropagation();
            syncQueryBuilderToConfig();
            startExport();
            return false;
        });

        table.on('click', '.fastcrud-delete-btn', function(event) {
            event.preventDefault();
            event.stopPropagation();
            syncQueryBuilderToConfig();
            var pk = getPkInfoFromElement(this);
            if (!pk) { showError('Unable to determine primary key for deletion.'); return false; }
            requestDelete({ __fastcrud_primary_key: pk.column, __fastcrud_primary_value: pk.value });
            return false;
        });

        table.on('click', '.fastcrud-multi-link-input', function(event) {
            event.preventDefault();
            event.stopPropagation();
            var button = $(this);
            var baseUrlRaw = button.data('fastcrudInputUrl');
            var baseUrl = typeof baseUrlRaw === 'string' ? baseUrlRaw : '';
            if (!baseUrl.trim()) {
                return false;
            }
            var paramNameRaw = button.data('fastcrudInputName');
            var paramName = typeof paramNameRaw === 'string' ? paramNameRaw : '';
            if (!paramName.trim()) {
                paramName = 'exampleinput';
            }
            var promptRaw = button.data('fastcrudInputPrompt');
            var promptMessage = '';
            if (typeof promptRaw === 'string' && promptRaw.trim()) {
                promptMessage = promptRaw.trim();
            } else {
                var ariaLabel = button.attr('aria-label') || '';
                var fallbackLabel = ariaLabel.trim();
                if (!fallbackLabel) {
                    var textLabel = (button.text() || '').trim();
                    fallbackLabel = textLabel || 'value';
                }
                promptMessage = 'Enter ' + fallbackLabel;
            }
            var inputValue = window.prompt(promptMessage, '');
            if (inputValue === null) {
                return false;
            }
            var valueString = String(inputValue);
            if (valueString.trim() === '') {
                return false;
            }
            var targetRaw = button.data('fastcrudInputTarget');
            var relRaw = button.data('fastcrudInputRel');
            var target = typeof targetRaw === 'string' ? targetRaw : '';
            var rel = typeof relRaw === 'string' ? relRaw : '';
            var finalUrl = buildUrlWithParam(baseUrl, paramName, valueString);
            followUrl(finalUrl, target, rel);
            return false;
        });

        // Removed handler for unused custom buttons.

        table.on('click', '.fastcrud-duplicate-btn', function(event) {
            event.preventDefault();
            event.stopPropagation();
            syncQueryBuilderToConfig();
            var pk = getPkInfoFromElement(this);
            if (!pk) { showError('Unable to determine primary key for duplication.'); return false; }
            requestDuplicate({ __fastcrud_primary_key: pk.column, __fastcrud_primary_value: pk.value });
            return false;
        });

        editOffcanvasElement.on('click', 'button[type="submit"]', function() {
            lastSubmitAction = jQuery(this).data('fastcrudSubmitAction') || 'close';
        });

        editForm.off('submit.fastcrud').on('submit.fastcrud', submitEditForm);

        function bootstrapInitialMode() {
            if (!initialMode || !formOnlyMode) {
                return false;
            }

            var resolvedPrimary = resolvePrimaryKeyColumn();

            if (initialMode === 'create') {
                editForm.data('mode', 'create');
                clearRowHighlight();

                if (!resolvedPrimary) {
                    showFormError('Unable to determine primary key for creating records.');
                    return true;
                }

                var templateRow = getFormTemplate('create');
                if (!templateRow) {
                    templateRow = {
                        __fastcrud_primary_key: resolvedPrimary,
                        __fastcrud_primary_value: null
                    };
                } else {
                    templateRow.__fastcrud_primary_key = resolvedPrimary || templateRow.__fastcrud_primary_key || null;
                    templateRow.__fastcrud_primary_value = null;
                }

                showEditForm(ensureRowColumns(templateRow));
                return true;
            }

            if (!resolvedPrimary) {
                showError('Primary key column missing for ' + initialMode + ' mode.');
                return true;
            }

            if (typeof initialPrimaryKeyValue === 'undefined' || initialPrimaryKeyValue === null ||
                (typeof initialPrimaryKeyValue === 'string' && initialPrimaryKeyValue === '')) {
                showError('Primary key value missing for ' + initialMode + ' mode.');
                return true;
            }

            if (initialMode === 'view') {
                fetchRowByPk(resolvedPrimary, initialPrimaryKeyValue, 'view')
                    .then(function(row) {
                        showViewPanel(row || {});
                    })
                    .catch(function(error) {
                        showError(error && error.message ? error.message : 'Failed to load record.');
                    });
                return true;
            }

            editForm.data('mode', 'edit');
            fetchRowByPk(resolvedPrimary, initialPrimaryKeyValue, 'edit')
                .then(function(row) {
                    showEditForm(row || {});
                })
                .catch(function(error) {
                    showError(error && error.message ? error.message : 'Failed to load record.');
                });

            return true;
        }

        window.FastCrudTables = window.FastCrudTables || {};
        window.FastCrudTables[tableId] = {
            destroy: function() {
                if (disposed) { return; }
                disposed = true;
                fetchSequence++;
                if (activeFetchRequest) { activeFetchRequest.abort(); }
                cancelPendingWidgets();
                if (widgetObserver) { widgetObserver.disconnect(); }
                destroyRichEditors(editFieldsContainer);
                destroySelect2(editFieldsContainer);
                destroyFilePonds(editFieldsContainer);
                destroyRowOrderingSortable();
                if (sidePanelHeightTimer !== null) {
                    window.clearTimeout(sidePanelHeightTimer);
                    sidePanelHeightTimer = null;
                }
                $(window).off('resize' + sidePanelResizeNamespace);
                [editOffcanvasElement, viewOffcanvasElement, queryBuilderModal].forEach(function(panel) {
                    if (!panel || !panel.length) { return; }
                    destroySelect2(panel);
                    if (window.bootstrap) {
                        [bootstrap.Modal, bootstrap.Offcanvas].forEach(function(component) {
                            var instance = component && component.getInstance(panel.get(0));
                            if (instance) { instance.dispose(); }
                        });
                    }
                    panel.remove();
                });
                delete window.FastCrudTables[tableId];
            },
            reload: function() {
                loadTableData(currentPage);
            },
            search: function(term, column) {
                currentSearchTerm = term || '';
                if (typeof column !== 'undefined' && column !== null) {
                    currentSearchColumn = column;
                    if (searchSelect) {
                        searchSelect.val(column);
                    }
                }

                if (searchInput && currentSearchTerm !== undefined) {
                    searchInput.val(currentSearchTerm);
                }

                loadTableData(1);
            },
            clearSearch: function() {
                currentSearchTerm = '';
                if (searchInput) {
                    searchInput.val('');
                }
                loadTableData(1);
            },
            setPerPage: function(value) {
                if (value === 'all') {
                    perPage = 0;
                } else {
                    var parsed = parseInt(value, 10);
                    if (!isNaN(parsed) && parsed > 0) {
                        perPage = parsed;
                    }
                }
                loadTableData(1);
            },
            getMeta: function() {
                return metaConfig;
            }
        };

        if (formOnlyMode) {
            var bootstrapRequest = loadTableData(1);
            if (bootstrapRequest && typeof bootstrapRequest.done === 'function') {
                bootstrapRequest.done(function() {
                    bootstrapInitialMode();
                });
            } else {
                bootstrapInitialMode();
            }
        } else {
            loadTableData(1);
        }
        });
    }
    (function __fastcrud_wait() {
        if (window.jQuery) {
            try { FastCrudInit(window.jQuery); } catch (e) { try { if (window.console && console.error) console.error('FastCrud init error', e); } catch (e2) {} }
        } else {
            setTimeout(__fastcrud_wait, 50);
        }
    })();
    }
    window.FastCrudRuntime = { init: initialize };
    var queue = window.FastCrudQueue || [];
    window.FastCrudQueue = [];
    queue.forEach(initialize);
})(window);
