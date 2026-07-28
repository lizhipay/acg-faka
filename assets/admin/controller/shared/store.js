!function () {
    let table, _LogPid, mobileRefreshTimer;
    let connectGeneration = 0;
    const namespace = '.mdSharedStoreController';
    const mobileAdminEnabled = () => Boolean(window.AdminMobile && window.AdminMobile.isEnabled && window.AdminMobile.isEnabled());
    const importStartIcon = '<svg class="md-message-send-icon md-import-start-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 8.5 12 4l8 4.5v9L12 22l-8-4.5Z"/><path d="m4 8.5 8 4.5 8-4.5M12 13v9M12 2v7m-3-3 3 3 3-3"/></svg>';
    const bindLayerFocus = ($layer, bringToFront = false) => {
        if (mobileAdminEnabled() || !$layer?.length || typeof layer.setTop !== 'function') return;
        if (!$layer.data('md-shared-store-focus-bound')) {
            layer.setTop($layer);
            $layer.data('md-shared-store-focus-bound', true);
        }
        if (bringToFront) $layer.triggerHandler('mousedown');
    };
    const cssPixels = value => {
        const pixels = Number.parseFloat(value);
        return Number.isFinite(pixels) ? pixels : 0;
    };
    const importPopupNaturalContentHeight = $body => {
        const body = $body?.get?.(0);
        const form = body?.querySelector?.(':scope > .layui-form');
        if (!body || !form) return 1;

        body.style.setProperty('box-sizing', 'border-box', 'important');
        body.style.setProperty('height', 'auto', 'important');
        body.style.setProperty('min-height', '0', 'important');
        body.style.setProperty('max-height', 'none', 'important');
        $(form).css({
            height: 'auto',
            minHeight: '0',
            maxHeight: 'none'
        });

        const bodyRect = body.getBoundingClientRect();
        const bodyStyle = window.getComputedStyle(body);
        let bottom = cssPixels(bodyStyle.paddingTop);

        Array.from(form.children).forEach(item => {
            const itemStyle = window.getComputedStyle(item);
            if (
                item.hidden
                || itemStyle.display === 'none'
                || itemStyle.visibility === 'hidden'
                || item.getClientRects().length === 0
            ) {
                return;
            }

            // The custom field may contain thousands of collapsed product rows.
            // Its visible picker boundary is the real end of the form; measuring
            // a stretched wrapper would recreate the full-screen blank area.
            const endpoint = item.querySelector('.md-remote-product-picker') || item;
            const endpointRect = endpoint.getBoundingClientRect();
            bottom = Math.max(
                bottom,
                endpointRect.bottom - bodyRect.top + cssPixels(itemStyle.marginBottom)
            );
        });

        return Math.max(1, Math.ceil(bottom + cssPixels(bodyStyle.paddingBottom)));
    };
    const fitImportPopupHeight = ($layer, resetScroll = false) => {
        if (
            mobileAdminEnabled()
            || !$layer?.length
            || !$layer.get(0)?.isConnected
            || $layer.is('[minleft]')
            || $layer.find('.layui-layer-max').hasClass('layui-layer-maxmin')
        ) {
            return;
        }

        const $content = $layer.children('.layui-layer-content');
        const $body = $content.children('.layui-card-body');
        if (!$content.length || !$body.length) return;
        const layerNode = $layer.get(0);
        const contentNode = $content.get(0);
        const bodyNode = $body.get(0);

        const viewportHeight = Math.floor(
            window.visualViewport?.height
            || document.documentElement.clientHeight
            || window.innerHeight
            || 0
        );
        const currentScrollTop = $content.scrollTop();

        // Layer and its maximize/restore lifecycle may leave explicit sizes
        // behind. Clear them before measuring the visible form boundary.
        layerNode.style.setProperty('height', 'auto', 'important');
        contentNode.style.setProperty('height', 'auto', 'important');
        contentNode.style.setProperty('max-height', 'none', 'important');
        contentNode.style.setProperty('overflow-y', 'visible', 'important');

        const frameHeight = Math.max(
            0,
            Math.ceil(
                ($layer.children('.layui-layer-title').outerHeight(true) || 0)
                + ($layer.children('.layui-layer-btn').outerHeight(true) || 0)
                + Math.max(0, $layer.outerHeight() - $layer.innerHeight())
            )
        );
        const naturalContentHeight = importPopupNaturalContentHeight($body);
        const targetLayerHeight = Math.min(920, Math.max(1, viewportHeight - 32));
        const maxContentHeight = Math.max(1, targetLayerHeight - frameHeight);
        const contentHeight = Math.min(naturalContentHeight, maxContentHeight);
        const layerHeight = contentHeight + frameHeight;

        bodyNode.style.setProperty('height', `${naturalContentHeight}px`, 'important');
        bodyNode.style.setProperty('overflow', 'hidden', 'important');
        contentNode.style.setProperty('height', `${contentHeight}px`, 'important');
        contentNode.style.setProperty('max-height', `${maxContentHeight}px`, 'important');
        contentNode.style.setProperty(
            'overflow-y',
            naturalContentHeight > maxContentHeight ? 'auto' : 'visible',
            'important'
        );
        layerNode.style.setProperty('height', `${layerHeight}px`, 'important');
        layerNode.style.setProperty('max-height', `${Math.max(1, viewportHeight - 32)}px`, 'important');

        if (resetScroll) {
            $content.scrollTop(0);
        } else {
            const maxScrollTop = Math.max(0, contentNode.scrollHeight - contentNode.clientHeight);
            contentNode.scrollTop = Math.min(currentScrollTop, maxScrollTop);
        }

        const top = Math.max(16, Math.floor((viewportHeight - $layer.outerHeight()) / 2));
        $layer.css('top', `${top}px`);
    };
    const controllerLayers = new Set();
    const controllerRequests = new Set();
    const importTasks = new Map();
    const importTaskLayers = new Map();
    const importTaskViews = new Map();
    const importTaskRunners = new Set();
    const importTaskVersion = 1;
    const manageId = Number(document.getElementById('kt_content')?.dataset.manageId);
    const importTaskStorageKey = Number.isSafeInteger(manageId) && manageId > 0
        ? `acgshop.admin.shared-store.import-tasks.v${importTaskVersion}.${manageId}`
        : null;
    let remoteProductPickerSequence = 0;
    let controllerActive = true;
    const trackRequest = request => {
        if (!request || typeof request.always !== 'function') return request;
        controllerRequests.add(request);
        request.always(() => controllerRequests.delete(request));
        return request;
    };
    const openControllerLayer = options => {
        const originalEnd = options.end;
        let index;
        try {
            index = layer.open({
                ...options,
                end: function () {
                    controllerLayers.delete(index);
                    if (typeof originalEnd === 'function') return originalEnd.apply(this, arguments);
                }
            });
        } catch (error) {
            if (typeof originalEnd === 'function') originalEnd();
            throw error;
        }
        if (controllerActive) controllerLayers.add(index); else layer.close(index);
        return index;
    };
    const storeId = value => {
        const id = Number(value);
        return Number.isSafeInteger(id) && id > 0 ? id : 0;
    };
    if (typeof window.__mdSharedStoreDestroy === 'function') window.__mdSharedStoreDestroy();
    const openExternal = value => {
        if (!value) return false;
        const source = /^[a-z][a-z0-9+.-]*:/i.test(value) ? value : 'https://' + value;
        try {
            const url = new URL(source, window.location.origin);
            if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password) return false;
            window.open(url.href, '_blank', 'noopener,noreferrer');
            return true;
        } catch (error) {
            return false;
        }
    };
    const escapeHtml = value => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    const plainMessage = value => {
        const template = document.createElement('template');
        template.innerHTML = String(value ?? '');
        return (template.content.textContent || '').trim();
    };
    const redactImportSecrets = value => String(value ?? '')
        .replace(/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b/g, '[JWT已隐藏]')
        .replace(/\b(Bearer|Basic)\s+[A-Za-z0-9._~+/=-]{8,}/gi, '$1 [已隐藏]')
        .replace(/\b(authorization|proxy-authorization|cookie|set-cookie)\b\s*[:=：]\s*[^,;，；\r\n]+/giu, '$1=[已隐藏]')
        .replace(/(["'])(app[-_ ]?key|api[-_ ]?key|password|passwd|secret|token|session[-_ ]?id)\1\s*:\s*(["'])[^"']*\3/giu, '$1$2$1:"[已隐藏]"')
        .replace(/\b(app[-_ ]?key|api[-_ ]?key|password|passwd|secret|token|session[-_ ]?id)\b(\s*[:=：]\s*)[^\s,;，；&]+/giu, '$1$2[已隐藏]');
    const compactLogText = (value, maxLength = 240) => redactImportSecrets(plainMessage(value))
        .replace(/[\u0000-\u001F\u007F]+/g, ' ')
        .replace(/\s+/g, ' ')
        .trim()
        .slice(0, maxLength);
    const importTaskStats = task => task.items.reduce((stats, item) => {
        if (item.status === 'success') stats.success++;
        if (item.status === 'failed') stats.failed++;
        return stats;
    }, {success: 0, failed: 0});
    const applyImportCategoryMode = (form, value) => {
        const automatic = String(value) === '1';
        if (form.form?.category_id) {
            form.form.category_id.required = !automatic;
        }
        automatic ? form.hide("category_id") : form.show("category_id");
    };
    const normalizeBinaryValue = value => value === true || String(value) === '1' ? '1' : '0';
    const normalizeImportRequest = value => {
        const categoryMode = String(value?.category_mode ?? '0');
        const categoryId = Number(value?.category_id);
        const premiumText = String(value?.premium ?? '').trim();
        const premium = Number(premiumText);
        const premiumType = String(value?.premium_type ?? '');
        if (
            !['0', '1'].includes(categoryMode)
            || (
                categoryMode === '0'
                && (
                    !Number.isSafeInteger(categoryId)
                    || categoryId < 1
                    || categoryId > 4294967295
                )
            )
            || premiumText === ''
            || premiumText.length > 32
            || !Number.isFinite(premium)
            || premium < 0
            || premium > 99999999.99
            || !['0', '1'].includes(premiumType)
        ) {
            return null;
        }
        return {
            category_mode: categoryMode,
            category_id: categoryMode === '0' ? String(categoryId) : '',
            image_download: normalizeBinaryValue(value?.image_download),
            shelves: normalizeBinaryValue(value?.shelves),
            shared_sync: normalizeBinaryValue(value?.shared_sync),
            shared_amount_sync: normalizeBinaryValue(value?.shared_amount_sync),
            shared_config_sync: normalizeBinaryValue(value?.shared_config_sync),
            premium_type: premiumType,
            premium: premiumText,
            resume_import: '1'
        };
    };
    const normalizeRemoteProductGroups = value => {
        if (!Array.isArray(value)) return [];
        const groups = [];
        const seenIds = new Set();
        const seenCodes = new Set();
        let total = 0;

        value.forEach(group => {
            if (total >= 10000 || !Array.isArray(group?.children)) return;
            const groupName = compactLogText(group?.name, 128);
            if (!groupName) return;
            const children = [];

            group.children.forEach(item => {
                if (total >= 10000) return;
                const idText = typeof item?.id === 'number'
                    ? String(item.id)
                    : String(item?.id ?? '').trim();
                const id = /^\d+$/.test(idText) ? Number(idText) : 0;
                const code = typeof item?.code === 'object' ? '' : String(item?.code ?? '').trim();
                const name = compactLogText(item?.name, 255);
                if (
                    !Number.isSafeInteger(id)
                    || id < 1
                    || id > 4294967295
                    || !code
                    || code.length > 64
                    || /[\u0000-\u0020\u007F]/.test(code)
                    || !name
                    || seenIds.has(id)
                    || seenCodes.has(code)
                ) {
                    return;
                }
                seenIds.add(id);
                seenCodes.add(code);
                children.push({id: id, code: code, name: name, categoryName: groupName});
                total++;
            });

            groups.push({name: groupName, children: children});
        });

        return groups;
    };
    const mountRemoteProductPicker = (_formInstance, $container, groups) => {
        const host = $container?.get?.(0) || $container?.[0];
        if (!host) return null;

        const createElement = (tag, className = '', text = '') => {
            const element = document.createElement(tag);
            if (className) element.className = className;
            if (text !== '') element.textContent = text;
            return element;
        };
        const createIcon = (name, extraClass = '') => {
            const icon = createElement('span', `material-icons-outlined${extraClass ? ` ${extraClass}` : ''}`, name);
            icon.setAttribute('aria-hidden', 'true');
            return icon;
        };
        const pickerId = `md-remote-product-picker-${++remoteProductPickerSequence}`;
        const total = groups.reduce((count, group) => count + group.children.length, 0);
        const root = createElement('section', 'md-remote-product-picker');
        root.setAttribute('aria-labelledby', `${pickerId}-title`);

        const header = createElement('header', 'md-remote-product-picker__header');
        const heading = createElement('div', 'md-remote-product-picker__heading');
        const headingIcon = createElement('span', 'md-remote-product-picker__heading-icon');
        headingIcon.appendChild(createIcon('inventory_2'));
        const headingText = createElement('div', 'md-remote-product-picker__heading-text');
        const title = createElement('h3', '', '远程商品');
        title.id = `${pickerId}-title`;
        headingText.append(
            title,
            createElement('p', '', '选择需要接入并入库的商品')
        );
        heading.append(headingIcon, headingText);

        const counters = createElement('div', 'md-remote-product-picker__counters');
        const totalCounter = createElement('span', 'md-remote-product-picker__counter', `共 ${total} 件`);
        const selectedCounter = createElement('span', 'md-remote-product-picker__counter md-remote-product-picker__counter--selected', '已选 0 件');
        selectedCounter.setAttribute('aria-live', 'polite');
        counters.append(totalCounter, selectedCounter);
        header.append(heading, counters);

        const toolbar = createElement('div', 'md-remote-product-picker__toolbar');
        const search = createElement('div', 'md-remote-product-picker__search');
        search.appendChild(createIcon('search'));
        const searchInput = createElement('input', 'md-remote-product-picker__search-input');
        searchInput.type = 'search';
        searchInput.placeholder = '搜索分类或商品名称';
        searchInput.autocomplete = 'off';
        searchInput.spellcheck = false;
        searchInput.setAttribute('aria-label', '搜索远程商品');
        const clearSearch = createElement('button', 'md-remote-product-picker__clear-search');
        clearSearch.type = 'button';
        clearSearch.dataset.action = 'clear-search';
        clearSearch.setAttribute('aria-label', '清空搜索');
        clearSearch.hidden = true;
        clearSearch.appendChild(createIcon('close'));
        search.append(searchInput, clearSearch);

        const toolbarActions = createElement('div', 'md-remote-product-picker__toolbar-actions');
        const selectionButton = createElement('button', 'md-remote-product-picker__action');
        selectionButton.type = 'button';
        selectionButton.dataset.action = 'toggle-all-selection';
        selectionButton.append(
            createIcon('select_all'),
            createElement('span', 'md-remote-product-picker__action-text', '全选')
        );
        const expandButton = createElement('button', 'md-remote-product-picker__action');
        expandButton.type = 'button';
        expandButton.dataset.action = 'toggle-all-groups';
        expandButton.append(
            createIcon('unfold_more'),
            createElement('span', 'md-remote-product-picker__action-text', '展开')
        );
        toolbarActions.append(selectionButton, expandButton);
        toolbar.append(search, toolbarActions);

        const list = createElement('div', 'md-remote-product-picker__list');
        list.setAttribute('role', 'group');
        list.setAttribute('aria-label', '远程商品分类');
        const records = [];
        const flatItems = [];

        groups.forEach((group, groupIndex) => {
            const groupId = `${pickerId}-group-${groupIndex}`;
            const groupNode = createElement('section', 'md-remote-product-picker__group');
            const groupHeader = createElement('div', 'md-remote-product-picker__group-header');
            const toggleButton = createElement('button', 'md-remote-product-picker__group-toggle');
            toggleButton.type = 'button';
            toggleButton.dataset.action = 'toggle-group';
            toggleButton.dataset.groupIndex = String(groupIndex);
            toggleButton.setAttribute('aria-expanded', 'false');
            toggleButton.setAttribute('aria-controls', groupId);
            toggleButton.setAttribute('aria-label', `展开分类 ${group.name}`);
            toggleButton.appendChild(createIcon('chevron_right'));

            const groupLabel = createElement('label', 'md-remote-product-picker__group-label');
            const groupCheckbox = createElement('input', 'md-remote-product-picker__checkbox md-remote-product-picker__group-checkbox');
            groupCheckbox.type = 'checkbox';
            groupCheckbox.dataset.groupIndex = String(groupIndex);
            groupCheckbox.setAttribute('lay-ignore', '');
            groupCheckbox.setAttribute('aria-label', `选择分类 ${group.name} 的全部商品`);
            groupCheckbox.disabled = group.children.length === 0;
            const groupCheckmark = createElement('span', 'md-remote-product-picker__checkmark');
            groupCheckmark.setAttribute('aria-hidden', 'true');
            const groupIcon = createIcon(group.children.length > 0 ? 'folder' : 'folder_off', 'md-remote-product-picker__item-icon');
            const groupName = createElement('span', 'md-remote-product-picker__group-name', group.name);
            const groupCount = createElement('span', 'md-remote-product-picker__group-count', `${group.children.length} 件`);
            groupLabel.append(groupCheckbox, groupCheckmark, groupIcon, groupName, groupCount);
            groupHeader.append(toggleButton, groupLabel);

            const groupItems = createElement('div', 'md-remote-product-picker__items');
            groupItems.id = groupId;
            groupItems.setAttribute('role', 'group');
            groupItems.setAttribute('aria-label', `${group.name} 商品`);
            const record = {
                node: groupNode,
                toggle: toggleButton,
                groupCheckbox: groupCheckbox,
                name: group.name,
                groupName: group.name.toLocaleLowerCase(),
                expanded: false,
                items: []
            };

            group.children.forEach(item => {
                const row = createElement('label', 'md-remote-product-picker__item');
                const checkbox = createElement('input', 'md-remote-product-picker__checkbox md-remote-product-picker__item-checkbox');
                checkbox.type = 'checkbox';
                checkbox.name = 'auth[]';
                checkbox.value = String(item.id);
                checkbox.dataset.groupIndex = String(groupIndex);
                checkbox.setAttribute('lay-ignore', '');
                checkbox.setAttribute('aria-label', `选择商品 ${item.name}`);
                const checkmark = createElement('span', 'md-remote-product-picker__checkmark');
                checkmark.setAttribute('aria-hidden', 'true');
                const itemIcon = createIcon('inventory_2', 'md-remote-product-picker__item-icon');
                const itemName = createElement('span', 'md-remote-product-picker__item-name', item.name);
                row.append(checkbox, checkmark, itemIcon, itemName);
                groupItems.appendChild(row);

                const itemRecord = {
                    row: row,
                    input: checkbox,
                    searchText: item.name.toLocaleLowerCase()
                };
                record.items.push(itemRecord);
                flatItems.push(itemRecord);
            });

            groupNode.append(groupHeader, groupItems);
            list.appendChild(groupNode);
            records.push(record);
        });

        const empty = createElement('div', 'md-remote-product-picker__empty');
        empty.append(
            createIcon('inventory_2'),
            createElement('strong', '', '暂无可接入的远程商品'),
            createElement('span', '', '请检查远程店铺的商品状态后重试')
        );
        empty.hidden = total > 0;
        list.appendChild(empty);
        root.append(header, toolbar, list);
        host.replaceChildren(root);

        const applyExpanded = (record, expanded) => {
            record.node.classList.toggle('is-expanded', expanded);
            record.toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            record.toggle.setAttribute('aria-label', `${expanded ? '收起' : '展开'}分类 ${record.name}`);
            const icon = record.toggle.querySelector('.material-icons-outlined');
            if (icon) icon.textContent = expanded ? 'expand_more' : 'chevron_right';
        };
        const setExpanded = (record, expanded) => {
            record.expanded = expanded;
            applyExpanded(record, expanded);
        };
        const updateExpandButton = () => {
            const visibleGroups = records.filter(record => !record.node.hidden);
            const allExpanded = visibleGroups.length > 0 && visibleGroups.every(record => record.node.classList.contains('is-expanded'));
            expandButton.disabled = visibleGroups.length === 0;
            expandButton.setAttribute('aria-label', allExpanded ? '收起全部分类' : '展开全部分类');
            const icon = expandButton.querySelector('.material-icons-outlined');
            const text = expandButton.querySelector('.md-remote-product-picker__action-text');
            if (icon) icon.textContent = allExpanded ? 'unfold_less' : 'unfold_more';
            if (text) text.textContent = allExpanded ? '收起' : '展开';
        };
        const updateGroupSelection = record => {
            const selected = record.items.reduce((count, item) => count + (item.input.checked ? 1 : 0), 0);
            record.groupCheckbox.checked = record.items.length > 0 && selected === record.items.length;
            record.groupCheckbox.indeterminate = selected > 0 && selected < record.items.length;
            record.node.classList.toggle('is-selected', selected > 0);
        };
        const updateSelection = (changedRecords = records) => {
            changedRecords.forEach(updateGroupSelection);
            const selected = flatItems.reduce((count, item) => count + (item.input.checked ? 1 : 0), 0);
            const allSelected = total > 0 && selected === total;
            selectedCounter.textContent = `已选 ${selected} 件`;
            selectedCounter.classList.toggle('is-active', selected > 0);
            selectionButton.disabled = total === 0;
            selectionButton.setAttribute('aria-label', allSelected ? '清空全部已选商品' : '选择全部商品');
            selectionButton.setAttribute('aria-pressed', allSelected ? 'true' : 'false');
            const icon = selectionButton.querySelector('.material-icons-outlined');
            const text = selectionButton.querySelector('.md-remote-product-picker__action-text');
            if (icon) icon.textContent = allSelected ? 'deselect' : 'select_all';
            if (text) text.textContent = allSelected ? '清空' : '全选';
        };
        const applySearch = () => {
            const query = searchInput.value.trim().toLocaleLowerCase();
            let visibleItems = 0;
            clearSearch.hidden = query === '';

            records.forEach(record => {
                const groupMatches = query !== '' && record.groupName.includes(query);
                let groupVisibleItems = 0;
                record.items.forEach(item => {
                    const matches = query === '' || groupMatches || item.searchText.includes(query);
                    item.row.hidden = !matches;
                    if (matches) groupVisibleItems++;
                });
                record.node.hidden = query !== '' && groupVisibleItems === 0;
                if (!record.node.hidden) visibleItems += groupVisibleItems;
                applyExpanded(record, query !== '' && groupVisibleItems > 0 ? true : record.expanded);
            });

            const hasItems = query === '' ? total > 0 : visibleItems > 0;
            empty.hidden = hasItems;
            const emptyTitle = empty.querySelector('strong');
            const emptyDescription = empty.querySelector('span:not(.material-icons-outlined)');
            if (emptyTitle) emptyTitle.textContent = query === '' ? '暂无可接入的远程商品' : '没有找到匹配的商品';
            if (emptyDescription) {
                emptyDescription.textContent = query === ''
                    ? '请检查远程店铺的商品状态后重试'
                    : '请尝试更换关键词';
            }
            updateExpandButton();
        };

        let searchFrame = 0;
        const requestFrame = callback => typeof window.requestAnimationFrame === 'function'
            ? window.requestAnimationFrame(callback)
            : window.setTimeout(callback, 16);
        const cancelFrame = frame => {
            if (typeof window.cancelAnimationFrame === 'function') {
                window.cancelAnimationFrame(frame);
            } else {
                window.clearTimeout(frame);
            }
        };
        const scheduleSearch = () => {
            if (searchFrame) cancelFrame(searchFrame);
            searchFrame = requestFrame(() => {
                searchFrame = 0;
                applySearch();
            });
        };
        const onInput = event => {
            if (event.target === searchInput) scheduleSearch();
        };
        const onChange = event => {
            const target = event.target;
            if (!(target instanceof HTMLInputElement) || !target.classList.contains('md-remote-product-picker__checkbox')) return;
            const groupIndex = Number(target.dataset.groupIndex);
            const record = records[groupIndex];
            if (!record) return;
            if (target.classList.contains('md-remote-product-picker__group-checkbox')) {
                record.items.forEach(item => {
                    item.input.checked = target.checked;
                });
            }
            updateSelection([record]);
        };
        const onClick = event => {
            const button = event.target.closest?.('[data-action]');
            if (!button || !root.contains(button)) return;
            const action = button.dataset.action;

            if (action === 'clear-search') {
                searchInput.value = '';
                applySearch();
                searchInput.focus();
                return;
            }
            if (action === 'toggle-group') {
                const record = records[Number(button.dataset.groupIndex)];
                if (record) {
                    setExpanded(record, !record.node.classList.contains('is-expanded'));
                    updateExpandButton();
                }
                return;
            }
            if (action === 'toggle-all-groups') {
                const visibleGroups = records.filter(record => !record.node.hidden);
                const expand = !visibleGroups.every(record => record.node.classList.contains('is-expanded'));
                visibleGroups.forEach(record => setExpanded(record, expand));
                updateExpandButton();
                return;
            }
            if (action === 'toggle-all-selection') {
                const select = !flatItems.every(item => item.input.checked);
                flatItems.forEach(item => {
                    item.input.checked = select;
                });
                updateSelection();
            }
        };

        root.addEventListener('input', onInput);
        root.addEventListener('change', onChange);
        root.addEventListener('click', onClick);
        updateSelection();
        updateExpandButton();

        return () => {
            if (searchFrame) cancelFrame(searchFrame);
            root.removeEventListener('input', onInput);
            root.removeEventListener('change', onChange);
            root.removeEventListener('click', onClick);
        };
    };
    const normalizeImportTaskItem = value => {
        const code = String(value?.code ?? '').trim();
        const name = compactLogText(value?.name, 255) || '未命名商品';
        const categoryName = compactLogText(value?.categoryName, 128);
        const retryCountValue = Number(value?.retryCount);
        const retryCount = Number.isSafeInteger(retryCountValue) && retryCountValue > 0 ? 1 : 0;
        if (!code || code.length > 64 || /[\u0000-\u0020\u007F]/.test(code)) return null;
        const status = ['pending', 'running', 'success', 'failed'].includes(value?.status)
            ? (value.status === 'running' ? 'pending' : value.status)
            : 'pending';
        return {
            code: code,
            name: name,
            categoryName: categoryName,
            status: status,
            reason: compactLogText(value?.reason, 200),
            retryCount: retryCount,
            retryReason: retryCount > 0 ? compactLogText(value?.retryReason, 200) : ''
        };
    };
    const normalizeImportTask = value => {
        const id = String(value?.id ?? '');
        const taskStoreId = Number(value?.storeId);
        const request = normalizeImportRequest(value?.request);
        if (
            !/^[a-z0-9-]{12,80}$/i.test(id)
            || !Number.isSafeInteger(taskStoreId)
            || taskStoreId < 1
            || taskStoreId > 4294967295
            || !request
            || !Array.isArray(value?.items)
            || value.items.length < 1
            || value.items.length > 10000
        ) {
            return null;
        }
        const items = value.items.map(normalizeImportTaskItem);
        if (
            items.some(item => !item)
            || (request.category_mode === '1' && items.some(item => !item.categoryName))
        ) {
            return null;
        }
        const terminal = items.every(item => ['success', 'failed'].includes(item.status));
        const storedStatus = ['paused', 'stopped', 'completed'].includes(value?.status) ? value.status : 'paused';
        return {
            id: id,
            storeId: taskStoreId,
            storeName: compactLogText(value?.storeName, 128) || `店铺 ${taskStoreId}`,
            createdAt: Number.isFinite(Number(value?.createdAt)) ? Number(value.createdAt) : Date.now(),
            updatedAt: Number.isFinite(Number(value?.updatedAt)) ? Number(value.updatedAt) : Date.now(),
            status: terminal ? 'completed' : (storedStatus === 'stopped' ? 'stopped' : 'paused'),
            pauseReason: compactLogText(value?.pauseReason, 200),
            request: request,
            items: items
        };
    };
    const updateImportTaskButton = () => {
        const count = importTasks.size;
        const active = Array.from(importTasks.values())
            .filter(task => !['completed', 'stopped'].includes(task.status)).length;
        const $button = $('.btn-import-tasks');
        $button.prop('hidden', count === 0).toggle(count > 0);
        $button.find('.import-task-count').text(count);
        $button.attr('title', active > 0 ? `${active} 个任务正在执行或等待续传` : `${count} 个已完成任务等待查看`);
    };
    const persistImportTasks = () => {
        if (!importTaskStorageKey) return false;
        try {
            if (importTasks.size === 0) {
                localStorage.removeItem(importTaskStorageKey);
            } else {
                localStorage.setItem(importTaskStorageKey, JSON.stringify({
                    version: importTaskVersion,
                    tasks: Array.from(importTasks.values())
                }));
            }
            updateImportTaskButton();
            return true;
        } catch (error) {
            updateImportTaskButton();
            return false;
        }
    };
    const loadImportTasks = () => {
        if (!importTaskStorageKey) return;
        try {
            const payload = JSON.parse(localStorage.getItem(importTaskStorageKey) || '{"tasks":[]}');
            if (Number(payload?.version ?? importTaskVersion) !== importTaskVersion || !Array.isArray(payload?.tasks)) {
                return;
            }
            payload.tasks.forEach(value => {
                const task = normalizeImportTask(value);
                if (task) importTasks.set(task.id, task);
            });
        } catch (error) {
            try {
                localStorage.removeItem(importTaskStorageKey);
            } catch (ignored) {
            }
        }
    };
    const createImportTaskId = () => {
        const bytes = new Uint32Array(2);
        if (window.crypto?.getRandomValues) {
            window.crypto.getRandomValues(bytes);
        } else {
            bytes[0] = Math.floor(Math.random() * 0xFFFFFFFF);
            bytes[1] = Math.floor(Math.random() * 0xFFFFFFFF);
        }
        return `${Date.now().toString(36)}-${bytes[0].toString(36)}-${bytes[1].toString(36)}`;
    };
    const importTaskLogText = task => {
        const lines = [`准备开始接入并入库，合计${task.items.length}个商品...`];
        task.items.forEach(item => {
            if (!['running', 'success', 'failed'].includes(item.status) && item.retryCount < 1) return;
            lines.push(`【${item.name}】开始入库..`);
            if (item.retryCount > 0) {
                lines.push(`【${item.name}】首次入库失败：${item.retryReason || '未返回具体原因'}`);
                lines.push(`【${item.name}】开始自动重试（1/1）..`);
            }
            if (item.status === 'success') {
                const successLabel = item.retryCount > 0 ? '自动重试成功' : '入库成功';
                lines.push(item.reason && item.reason !== '入库成功'
                    ? `【${item.name}】${successLabel}：${item.reason}`
                    : `【${item.name}】${successLabel}`);
            } else if (item.status === 'failed') {
                const failureLabel = item.retryCount > 0 ? '自动重试失败' : '入库失败';
                lines.push(`【${item.name}】${failureLabel}：${item.reason || '未返回具体原因'}`);
            }
        });
        const stats = importTaskStats(task);
        if (task.status === 'completed') {
            lines.push('', `接入完成，合计${task.items.length}个商品，成功${stats.success}个，失败${stats.failed}个。`);
        } else if (task.status === 'stopped') {
            lines.push('', `任务已停止：${task.pauseReason || '提交参数或店铺状态已失效'}`);
        } else if (task.status === 'paused' && task.pauseReason) {
            lines.push('', `任务已暂停：${task.pauseReason}`);
        }
        return lines.join('\n');
    };
    const scrollImportTaskLog = $textarea => {
        const textarea = $textarea?.get?.(0);
        if (textarea) textarea.scrollTop = textarea.scrollHeight;
    };
    const renderImportTaskLog = task => {
        const view = importTaskViews.get(task.id);
        if (!view?.$textarea) return;
        view.$textarea.val(importTaskLogText(task));
        scrollImportTaskLog(view.$textarea);
    };
    const appendImportTaskLog = (task, line) => {
        const view = importTaskViews.get(task.id);
        if (!view?.$textarea) return;
        const current = view.$textarea.val();
        view.$textarea.val(current ? `${current}\n${line}` : line);
        scrollImportTaskLog(view.$textarea);
    };
    const updateImportTaskLayer = task => {
        const view = importTaskViews.get(task.id);
        if (!view?.$layer) return;
        const stats = importTaskStats(task);
        const processed = stats.success + stats.failed;
        const canRetryFailed = task.status === 'completed'
            && stats.failed > 0
            && !importTaskRunners.has(task.id);
        view.$layer.toggleClass('has-failed-import-retry', canRetryFailed);
        view.$layer.find('.import-task-progress').text(`${processed}/${task.items.length}`);
        const $retryButton = view.$layer.find('.layui-layer-btn0');
        $retryButton
            .toggle(canRetryFailed)
            .attr('aria-disabled', canRetryFailed ? 'false' : 'true')
            .attr('aria-hidden', canRetryFailed ? 'false' : 'true')
            .html(
                util.icon("fa-duotone fa-regular fa-rotate-right")
                + ` 继续导入失败商品（${stats.failed}）`
            );
        if (canRetryFailed) {
            $retryButton.removeAttr('tabindex');
        } else {
            $retryButton.attr('tabindex', '-1');
        }
        view.$layer.find('.layui-layer-btn1').html(
            util.icon("fa-duotone fa-regular fa-window-minimize")
            + (['completed', 'stopped'].includes(task.status) ? ' 关闭日志' : ' 后台运行')
        );
    };
    const openImportTaskLog = task => {
        if (!task || importTaskLayers.has(task.id) || !controllerActive) return;
        const mobile = mobileAdminEnabled();
        const slot = importTaskLayers.size % 6;
        let layerIndex = null;
        layerIndex = openControllerLayer({
            type: 1,
            shade: mobile ? 0.18 : false,
            shadeClose: false,
            title: `${util.icon("fa-duotone fa-regular fa-list-check")} 入库日志 · ${escapeHtml(task.storeName)} <span class="import-task-progress"></span>`,
            btn: [
                util.icon("fa-duotone fa-regular fa-rotate-right") + " 继续导入失败商品",
                util.icon("fa-duotone fa-regular fa-window-minimize") + " 后台运行"
            ],
            content: '<textarea class="log-textarea form-control" aria-label="商品入库日志" readonly style="width:100%;height:100%;resize:none;"></textarea>',
            area: mobile ? ["100%", "100%"] : ["760px", "560px"],
            offset: mobile ? 'auto' : [`${70 + slot * 24}px`, `${90 + slot * 28}px`],
            skin: mobile
                ? 'admin-mobile-layer-popup admin-mobile-layer-popup--task md-store-sync-log-layer md-store-import-log-layer'
                : 'md-store-sync-log-layer md-store-import-log-layer',
            maxmin: !mobile,
            resize: !mobile,
            move: mobile ? false : '.layui-layer-title',
            btn1: () => {
                continueFailedImportTask(task);
                return false;
            },
            btn2: index => {
                layer.close(index);
                return false;
            },
            success: layero => {
                const $layer = $(layero);
                const $textarea = $layer.find('.log-textarea').first();
                bindLayerFocus($layer);
                importTaskViews.set(task.id, {$layer: $layer, $textarea: $textarea});
                renderImportTaskLog(task);
                updateImportTaskLayer(task);
            },
            end: () => {
                importTaskLayers.delete(task.id);
                importTaskViews.delete(task.id);
                if (!controllerActive) return;
                const current = importTasks.get(task.id);
                if (current && ['completed', 'stopped'].includes(current.status)) {
                    importTasks.delete(task.id);
                    persistImportTasks();
                }
                updateImportTaskButton();
            }
        });
        importTaskLayers.set(task.id, layerIndex);
    };
    const importItemRequest = (task, item) => new Promise(resolve => {
        let request;
        try {
            const requestData = {...task.request, item_codes: [item.code]};
            if (task.request.category_mode === '1') {
                requestData.remote_category_name = item.categoryName;
            }
            request = trackRequest($.ajax({
                type: 'post',
                url: `/admin/api/store/addItem?storeId=${task.storeId}`,
                data: requestData
            }));
        } catch (error) {
            resolve({pause: true, retryable: false, message: '浏览器无法创建入库请求，请刷新页面后续传'});
            return;
        }
        request.done(response => {
            if (Number(response?.code) === 0) {
                resolve({
                    pause: true,
                    retryable: false,
                    message: compactLogText(response?.msg, 200) || '登录会话已失效，请重新登录'
                });
                return;
            }
            if (Number(response?.code) !== 200) {
                resolve({
                    fatal: true,
                    retryable: false,
                    message: compactLogText(response?.msg, 200) || '提交参数或店铺状态已失效'
                });
                return;
            }
            const result = Array.isArray(response?.data?.results) ? response.data.results[0] : null;
            if (result) {
                const success = result.success === true || Number(result.success) === 1;
                resolve({
                    success: success,
                    retryable: !success,
                    message: compactLogText(result.message, 200)
                });
                return;
            }
            const failed = Number(response?.data?.error ?? 0) > 0 || /失败[：:]\s*[1-9]/.test(String(response?.msg ?? ''));
            resolve({
                success: !failed,
                retryable: failed,
                message: compactLogText(response?.msg, 200) || (failed ? '入库失败' : '入库成功')
            });
        }).fail((xhr, status) => {
            if (status === 'abort') {
                resolve({aborted: true});
                return;
            }
            resolve({
                pause: true,
                retryable: true,
                message: compactLogText(xhr?.responseJSON?.msg, 200) || '网络异常，请检查连接后刷新页面续传'
            });
        });
    });
    const importItemRequestWithRetry = async (task, item) => {
        const outcome = await importItemRequest(task, item);
        if (outcome.retryable !== true || item.retryCount >= 1) return outcome;

        item.retryCount = 1;
        item.retryReason = outcome.message || '未返回具体原因';
        task.updatedAt = Date.now();
        if (!persistImportTasks()) {
            return {
                pause: true,
                retryable: false,
                message: '浏览器缓存空间不足，无法安全记录重试状态'
            };
        }
        appendImportTaskLog(task, `【${item.name}】首次入库失败：${item.retryReason}`);
        appendImportTaskLog(task, `【${item.name}】开始自动重试（1/1）..`);
        updateImportTaskLayer(task);
        if (!controllerActive) return {aborted: true};
        return importItemRequest(task, item);
    };
    const runImportTask = task => {
        if (
            !task
            || importTaskRunners.has(task.id)
            || ['completed', 'stopped'].includes(task.status)
            || !controllerActive
        ) {
            return;
        }
        importTaskRunners.add(task.id);
        task.status = 'running';
        task.pauseReason = '';
        task.updatedAt = Date.now();
        persistImportTasks();
        updateImportTaskLayer(task);

        (async () => {
            for (const item of task.items) {
                if (!controllerActive) break;
                if (['success', 'failed'].includes(item.status)) continue;
                item.status = 'running';
                item.reason = '';
                task.updatedAt = Date.now();
                if (!persistImportTasks()) {
                    item.status = 'pending';
                    task.status = 'paused';
                    task.pauseReason = '浏览器缓存空间不足，请关闭已完成日志后重试';
                    renderImportTaskLog(task);
                    break;
                }
                if (item.retryCount > 0) {
                    renderImportTaskLog(task);
                } else {
                    appendImportTaskLog(task, `【${item.name}】开始入库..`);
                }
                updateImportTaskLayer(task);

                const outcome = await importItemRequestWithRetry(task, item);
                if (!controllerActive || outcome.aborted) {
                    item.status = 'pending';
                    task.status = 'paused';
                    task.pauseReason = '';
                    break;
                }
                if (outcome.pause) {
                    item.status = 'pending';
                    task.status = 'paused';
                    task.pauseReason = outcome.message;
                    task.updatedAt = Date.now();
                    persistImportTasks();
                    appendImportTaskLog(task, `任务已暂停：${outcome.message}`);
                    break;
                }
                if (outcome.fatal) {
                    item.status = 'failed';
                    item.reason = outcome.message;
                    task.status = 'stopped';
                    task.pauseReason = outcome.message;
                    task.updatedAt = Date.now();
                    persistImportTasks();
                    appendImportTaskLog(task, `【${item.name}】入库失败：${outcome.message}`);
                    appendImportTaskLog(task, `任务已停止：${outcome.message}`);
                    break;
                }

                item.status = outcome.success ? 'success' : 'failed';
                item.reason = outcome.message || (outcome.success ? '入库成功' : '未返回具体原因');
                task.updatedAt = Date.now();
                if (!persistImportTasks()) {
                    task.status = 'paused';
                    task.pauseReason = '浏览器缓存空间不足，请关闭已完成日志后刷新页面续传';
                }
                const resultLabel = item.retryCount > 0
                    ? (outcome.success ? '自动重试成功' : '自动重试失败')
                    : (outcome.success ? '入库成功' : '入库失败');
                appendImportTaskLog(task, outcome.success && item.reason === '入库成功'
                    ? `【${item.name}】${resultLabel}`
                    : `【${item.name}】${resultLabel}：${item.reason}`);
                updateImportTaskLayer(task);
                if (task.status === 'paused') {
                    appendImportTaskLog(task, `任务已暂停：${task.pauseReason}`);
                    break;
                }
            }

            if (task.items.every(item => ['success', 'failed'].includes(item.status))) {
                task.status = 'completed';
                task.pauseReason = '';
                task.updatedAt = Date.now();
                persistImportTasks();
                const stats = importTaskStats(task);
                appendImportTaskLog(task, '');
                appendImportTaskLog(task, `接入完成，合计${task.items.length}个商品，成功${stats.success}个，失败${stats.failed}个。`);
                if (controllerActive) {
                    message.success(`入库任务完成：成功 ${stats.success} 个，失败 ${stats.failed} 个`);
                }
            }
        })().catch(() => {
            task.status = 'paused';
            task.pauseReason = '页面处理异常，请刷新后续传';
            task.updatedAt = Date.now();
            persistImportTasks();
            appendImportTaskLog(task, `任务已暂停：${task.pauseReason}`);
        }).finally(() => {
            importTaskRunners.delete(task.id);
            updateImportTaskLayer(task);
            updateImportTaskButton();
        });
    };
    const continueFailedImportTask = task => {
        if (
            !task
            || task.status !== 'completed'
            || importTaskRunners.has(task.id)
            || !controllerActive
        ) {
            return false;
        }
        const failedItems = task.items.filter(item => item.status === 'failed');
        if (failedItems.length === 0) {
            message.error('当前任务没有需要继续导入的失败商品');
            updateImportTaskLayer(task);
            return false;
        }

        const taskSnapshot = {
            status: task.status,
            pauseReason: task.pauseReason,
            updatedAt: task.updatedAt
        };
        const itemSnapshots = failedItems.map(item => ({
            item: item,
            status: item.status,
            reason: item.reason,
            retryCount: item.retryCount,
            retryReason: item.retryReason
        }));
        failedItems.forEach(item => {
            item.status = 'pending';
            item.reason = '';
            item.retryCount = 0;
            item.retryReason = '';
        });
        task.status = 'paused';
        task.pauseReason = '';
        task.updatedAt = Date.now();

        if (!persistImportTasks()) {
            task.status = taskSnapshot.status;
            task.pauseReason = taskSnapshot.pauseReason;
            task.updatedAt = taskSnapshot.updatedAt;
            itemSnapshots.forEach(snapshot => {
                snapshot.item.status = snapshot.status;
                snapshot.item.reason = snapshot.reason;
                snapshot.item.retryCount = snapshot.retryCount;
                snapshot.item.retryReason = snapshot.retryReason;
            });
            message.error('浏览器缓存空间不足，无法继续导入失败商品');
            updateImportTaskLayer(task);
            return false;
        }

        appendImportTaskLog(task, '');
        appendImportTaskLog(task, `继续导入失败商品，合计${failedItems.length}个...`);
        updateImportTaskLayer(task);
        runImportTask(task);
        return true;
    };
    const createImportTask = (taskStoreId, storeName, request, queue) => {
        const normalizedRequest = normalizeImportRequest(request);
        if (!normalizedRequest || !Array.isArray(queue) || queue.length === 0 || queue.length > 10000) return null;
        const task = normalizeImportTask({
            id: createImportTaskId(),
            storeId: taskStoreId,
            storeName: storeName,
            createdAt: Date.now(),
            updatedAt: Date.now(),
            status: 'paused',
            request: normalizedRequest,
            items: queue.map(item => ({...item, status: 'pending', reason: ''}))
        });
        if (!task) return null;
        importTasks.set(task.id, task);
        if (!persistImportTasks()) {
            importTasks.delete(task.id);
            persistImportTasks();
            return null;
        }
        openImportTaskLog(task);
        runImportTask(task);
        return task;
    };
    const normalizeHttpUrl = value => {
        const raw = String(value || '').trim();
        if (!/^https?:\/\//i.test(raw)) return null;
        try {
            const url = new URL(raw);
            return ['http:', 'https:'].includes(url.protocol) && !url.username && !url.password ? url : null;
        } catch (error) {
            return null;
        }
    };
    const renderStoreName = (name, domain) => {
        const safeName = escapeHtml(name || '-');
        const base = normalizeHttpUrl(domain);
        if (!base) {
            return `<span class="table-item"><span class="table-item-icon material-icons-outlined" aria-hidden="true">storefront</span><span class="table-item-name">${safeName}</span></span>`;
        }
        const favicon = new URL('/favicon.ico', base).href;
        return `<span class="table-item"><img src="${escapeHtml(favicon)}" class="table-item-icon" alt=""><span class="table-item-name">${safeName}</span></span>`;
    };
    const renderStoreLink = domain => {
        const label = escapeHtml(domain || '-');
        const url = normalizeHttpUrl(domain);
        return url ? `<a href="${escapeHtml(url.href)}" target="_blank" rel="noopener noreferrer">${label}</a>` : label;
    };

    table = new Table("/admin/api/store/data", "#shared-store-table");

    const refreshMobile = reason => {
        if (!controllerActive) return;
        clearTimeout(mobileRefreshTimer);
        mobileRefreshTimer = setTimeout(() => {
            if (!controllerActive) return;
            if (!table || table.isDestroyed || typeof table.getMobileSnapshot !== 'function' || !table.$table) {
                return;
            }
            if (typeof table.refreshMobile === 'function') {
                table.refreshMobile(reason);
                return;
            }
            const snapshot = table.getMobileSnapshot(reason);
            const payload = {table, snapshot, reason};
            const event = $.Event('admin:table:update');
            event.detail = payload;
            table.$table.trigger(event, [payload]);
        }, 0);
    };

    const modal = (title, assign = {}) => {
        const editing = Number(assign && assign.id) > 0;
        let submitting = false;
        // Only non-sensitive, editable values are allowed into Form.assign.
        // app_key is intentionally absent even if a stale caller still has it.
        const safeAssign = editing ? {
            id: Number(assign.id),
            type: Number(assign.type),
            domain: String(assign.domain || ''),
            app_id: String(assign.app_id || '')
        } : {};
        component.popup({
            submit: (data, index) => {
                if (!controllerActive || submitting) return;
                submitting = true;
                util.post({
                    url: '/admin/api/store/save',
                    data: data,
                    done: res => {
                        if (!controllerActive) return;
                        if (index !== undefined && index !== null) layer.close(index);
                        message.success(res?.msg && res.msg !== 'success' ? (plainMessage(res.msg) || '店铺已保存') : '店铺已保存');
                        if (table && !table.isDestroyed) table.refresh();
                    },
                    error: res => {
                        submitting = false;
                        if (controllerActive) message.error(plainMessage(res?.msg) || '店铺保存失败，请检查地址和凭据。');
                    },
                    fail: () => {
                        submitting = false;
                        if (controllerActive) message.error('网络异常，店铺资料未保存。');
                    }
                });
            },
            tab: [
                {
                    name: title,
                    form: [
                        {
                            title: "协议",
                            name: "type",
                            type: "select",
                            placeholder: "请选择协议",
                            dict: "_shared_type",
                            default: 0,
                            required: true
                        },
                        {
                            title: "店铺地址",
                            name: "domain",
                            type: "input",
                            placeholder: "需要带http://或者https://(推荐,如果支持)",
                            required: true
                        },
                        {
                            title: "商户ID", name: "app_id", type: "input", placeholder: "请输入商户ID",
                            required: true,
                            regex: {
                                value: '^[A-Za-z0-9._:@-]{1,32}$',
                                message: '商户ID必须是 1–32 位字母、数字或 . _ : @ -'
                            }
                        },
                        {
                            title: "商户密钥",
                            name: "app_key",
                            type: "password",
                            placeholder: editing ? "不修改请留空" : "请输入商户密钥",
                            required: !editing,
                            regex: {
                                value: '^[^\\s\\x00-\\x1F\\x7F]{1,64}$',
                                message: '商户密钥必须是 1–64 位且不能包含空白字符'
                            }
                        },
                    ]
                },
            ],
            autoPosition: true,
            height: "auto",
            assign: safeAssign,
            width: "580px",
            renderComplete: unique => {
                const $form = $('.' + unique);
                $form.find('input[name="domain"]').attr({
                    inputmode: 'url',
                    autocomplete: 'url',
                    autocapitalize: 'none',
                    spellcheck: 'false',
                    maxlength: '128'
                });
                $form.find('input[name="app_id"]').attr({
                    autocomplete: 'off',
                    autocapitalize: 'none',
                    spellcheck: 'false',
                    maxlength: '32'
                });
                $form.find('input[name="app_key"]')
                    .attr({
                        type: 'password',
                        autocomplete: 'new-password',
                        autocapitalize: 'none',
                        spellcheck: 'false',
                        maxlength: '64'
                    })
                    .val('');
            }
        });
    }


    table.setColumns([
        {checkbox: true}
        , {
            field: 'name', title: '店铺名称', formatter: (a, b) => {
                return renderStoreName(a, b?.domain);
            }
        }, {
            field: 'domain', title: '店铺地址', formatter: renderStoreLink
        }, {
            field: 'balance', title: '余额(缓存)', formatter: _ => format.money(_, "var(--md-success)")
        }, {
            field: 'status', title: '状态', formatter: function (val, item) {
                if (item.__mobileConnectStatus) {
                    return format.badge(
                        escapeHtml(item.__mobileConnectStatus.message),
                        item.__mobileConnectStatus.success ? "a-badge-success" : "a-badge-danger"
                    );
                }
                return '<span class="connect-' + item.id + '"><span class="badge badge-light-primary">连接中..</span></span>'
            }
        }, {
            field: 'type', title: '协议', dict: "_shared_type"
        },
        {
            field: 'operation', class: "nowrap", title: '操作', type: 'button', buttons: [
                {
                    icon: 'fa-duotone fa-regular fa-arrows-rotate',
                    tips: "一键同步此店铺下的所有本地商品数据",
                    class: "text-primary",
                    click: (event, value, row, index) => {
                        const id = storeId(row?.id);
                        if (!id) {
                            message.error('店铺编号无效，请刷新页面后重试');
                            return;
                        }
                        let logPid = _LogPid = util.generateRandStr(16);

                        trackRequest($.get(`/admin/api/store/getSyncRemoteLog?id=${id}`)).done(response => {
                            if (!controllerActive) return;
                            if (response?.code !== 200) {
                                message.error(plainMessage(response?.msg) || '同步日志读取失败');
                                return;
                            }
                            const data = response?.data || {};
                            const mobile = mobileAdminEnabled();
                            let syncing = false;
                            let $logText = null;
                            openControllerLayer({
                                type: 1,
                                shade: 0.4,
                                shadeClose: true,
                                title: '<i class="fa-duotone fa-regular fa-ban-bug"></i> 同步日志',
                                btn: [util.icon("fa-duotone fa-regular fa-arrows-rotate") + "<span class='sync-item-btn'>开始同步</span>", util.icon(`fa-duotone fa-regular fa-broom-wide`) + "清空日志", util.icon("fa-duotone fa-regular fa-xmark") + "关闭"],
                                content: '<textarea class="log-textarea form-control" style="width:100%;height:100%;resize:none;"></textarea>',
                                area: mobile ? ["100%", "100%"] : ["860px", "660px"],
                                skin: mobile ? 'admin-mobile-layer-popup admin-mobile-layer-popup--task admin-mobile-layer-popup--danger-action md-store-sync-log-layer' : 'md-store-sync-log-layer',
                                maxmin: !mobile,
                                resize: !mobile,
                                move: !mobile,
                                btn1: (index, layero) => {
                                    if (syncing) {
                                        layer.msg("同步任务正在进行，请勿重复提交");
                                        return false;
                                    }
                                    const startSync = () => {
                                        if (!controllerActive || _LogPid !== logPid || syncing) return;
                                        syncing = true;
                                        layer.msg("开始同步，请观察日志..");
                                        layero.find('.sync-item-btn').html("正在同步..");
                                        trackRequest($.post(`/admin/api/store/syncRemote?id=${id}`))
                                            .done(res => {
                                                if (!controllerActive || _LogPid !== logPid) return;
                                                if (res?.code === 200) {
                                                    layer.msg(escapeHtml(plainMessage(res?.msg) || "同步任务已结束"));
                                                } else {
                                                    message.error(plainMessage(res?.msg) || "同步任务执行失败，请检查同步日志");
                                                }
                                            })
                                            .fail((xhr, status) => {
                                                if (!controllerActive || _LogPid !== logPid || status === 'abort') return;
                                                message.error("网络异常，无法确认同步任务状态，请检查同步日志后再操作");
                                            })
                                            .always(() => {
                                                syncing = false;
                                                if (!controllerActive || _LogPid !== logPid) return;
                                                layero.find('.sync-item-btn').html("开始同步");
                                            });
                                    };
                                    if (mobileAdminEnabled()) {
                                        message.ask('同步会批量更新该远端店铺关联的本地商品数据。确认现在开始吗？', startSync, '确认同步商品？', '开始同步');
                                    } else {
                                        startSync();
                                    }
                                    return false;
                                },
                                btn2: (index, layero) => {
                                    message.ask('清空后，当前店铺的全部同步日志将被永久删除，且无法恢复。确认继续吗？', () => {
                                        if (!controllerActive || _LogPid !== logPid) return;
                                        trackRequest($.post(`/admin/api/store/clearSyncRemoteLog?id=${id}`))
                                            .done(res => {
                                                if (!controllerActive || _LogPid !== logPid) return;
                                                if (res?.code !== 200) {
                                                    message.error(plainMessage(res?.msg) || '同步日志清空失败');
                                                    return;
                                                }
                                                layer.msg("日志已清空");
                                                if ($logText) $logText.val("");
                                            })
                                            .fail((xhr, status) => {
                                                if (!controllerActive || _LogPid !== logPid || status === 'abort') return;
                                                message.error('网络异常，同步日志未清空');
                                            });
                                    }, '确认清空同步日志？', '确认清空');
                                    return false;
                                },
                                success: (layero, index) => {
                                    $logText = layero.find('.log-textarea');
                                    $logText.val(data?.log ?? '');
                                    util.timer(() => {
                                        return new Promise(resolve => {
                                            if (!controllerActive || _LogPid !== logPid) {
                                                resolve(false);
                                                return;
                                            }
                                            trackRequest($.get(`/admin/api/store/getSyncRemoteLog?id=${id}`, res => {
                                                if (!controllerActive || _LogPid !== logPid) {
                                                    resolve(false);
                                                    return;
                                                }
                                                const nextLog = res?.data?.log ?? '';
                                                if ($logText && nextLog != $logText.val()) {
                                                    $logText.val(nextLog);
                                                }
                                                resolve(true);
                                            })).fail(() => resolve(controllerActive && _LogPid === logPid));
                                        });
                                    }, 1500);
                                },
                                end: () => {
                                    $logText = null;
                                    if (_LogPid === logPid) _LogPid = null;
                                }
                            });
                        }).fail((xhr, status) => {
                            if (!controllerActive || status === 'abort') return;
                            message.error('网络异常，无法读取同步日志');
                        });
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-link',
                    tips: "接入货源",
                    class: "text-primary",
                    click: (event, value, row, index) => {
                        const id = storeId(row?.id);
                        if (!id) {
                            message.error('店铺编号无效，请刷新页面后重试');
                            return;
                        }
                        util.post("/admin/api/store/items", {id: id}, res => {
                            if (!controllerActive) return;
                            if (!Array.isArray(res?.data)) {
                                message.error('远端商品数据格式不正确，已阻止接入');
                                return;
                            }
                            const remoteGroups = normalizeRemoteProductGroups(res.data);
                            const items = new Map();
                            let creatingImportTask = false;

                            remoteGroups.forEach(group => {
                                group.children.forEach(item => {
                                    items.set(String(item.id), item);
                                });
                            });

                            let importPopupResizeObserver = null;
                            let importPopupResizeFrame = 0;
                            let importPopupResizeEvent = '';
                            let $importPopupLayer = null;
                            let resetImportPopupScroll = false;
                            const cancelImportPopupFrame = () => {
                                if (!importPopupResizeFrame) return;
                                if (typeof window.cancelAnimationFrame === 'function') {
                                    window.cancelAnimationFrame(importPopupResizeFrame);
                                } else {
                                    window.clearTimeout(importPopupResizeFrame);
                                }
                                importPopupResizeFrame = 0;
                            };
                            const scheduleImportPopupFit = (resetScroll = false) => {
                                resetImportPopupScroll = resetImportPopupScroll || resetScroll;
                                cancelImportPopupFrame();
                                const callback = () => {
                                    importPopupResizeFrame = 0;
                                    const shouldResetScroll = resetImportPopupScroll;
                                    resetImportPopupScroll = false;
                                    fitImportPopupHeight($importPopupLayer, shouldResetScroll);
                                };
                                importPopupResizeFrame = typeof window.requestAnimationFrame === 'function'
                                    ? window.requestAnimationFrame(callback)
                                    : window.setTimeout(callback, 16);
                            };
                            const destroyImportPopupSizing = () => {
                                cancelImportPopupFrame();
                                importPopupResizeObserver?.disconnect();
                                importPopupResizeObserver = null;
                                if (importPopupResizeEvent) {
                                    $(window).off(importPopupResizeEvent);
                                    $importPopupLayer?.off(importPopupResizeEvent);
                                }
                                importPopupResizeEvent = '';
                                $importPopupLayer = null;
                            };

                            component.popup({
                                submit: (result, index) => {
                                    if (!controllerActive) return;
                                    if (creatingImportTask) {
                                        layer.msg("正在创建入库任务，请勿重复点击");
                                        return;
                                    }
                                    const selectedItems = Array.isArray(result.auth) ? result.auth : [];
                                    if (selectedItems.length === 0) {
                                        layer.msg("至少选择一个远端店铺的商品");
                                        return;
                                    }

                                    const queue = [];
                                    const seenCodes = new Set();

                                    selectedItems.forEach(itemId => {
                                        const item = items.get(String(itemId));
                                        const code = item && typeof item.code !== 'object' ? String(item.code ?? '').trim() : '';
                                        if (!code || seenCodes.has(code)) return;
                                        seenCodes.add(code);
                                        queue.push({
                                            code: code,
                                            name: compactLogText(item?.name, 255) || '未命名商品',
                                            categoryName: compactLogText(item?.categoryName, 128)
                                        });
                                    });

                                    if (queue.length === 0) {
                                        layer.msg("所选远端商品已失效，请刷新后重新选择");
                                        return;
                                    }
                                    if (!normalizeImportRequest(result)) {
                                        layer.msg(
                                            String(result?.category_mode ?? '0') === '1'
                                                ? "请检查分类导入模式、加价模式和加价数额"
                                                : "请选择有效本地分类，并检查加价模式和加价数额"
                                        );
                                        return;
                                    }

                                    creatingImportTask = true;
                                    const task = createImportTask(
                                        id,
                                        compactLogText(row?.name, 128) || `店铺 ${id}`,
                                        result,
                                        queue
                                    );
                                    if (!task) {
                                        creatingImportTask = false;
                                        message.error("入库任务无法写入浏览器缓存，请关闭已完成日志或减少本次商品数量");
                                        return;
                                    }
                                    window.setTimeout(() => {
                                        creatingImportTask = false;
                                    }, 800);
                                    bindLayerFocus($(`#layui-layer${index}`), true);
                                    layer.msg(`已创建入库任务，共 ${queue.length} 个商品`);
                                },
                                tab: [
                                    {
                                        name: util.icon("fa-duotone fa-regular fa-link") + " 接入货源",
                                        form: [
                                            {
                                                title: "分类导入模式",
                                                name: "category_mode",
                                                type: "radio",
                                                dict: [
                                                    {id: 0, name: "导入到本地分类"},
                                                    {id: 1, name: "自动创建对应分类"}
                                                ],
                                                default: 0,
                                                required: true,
                                                change: applyImportCategoryMode
                                            },
                                            {
                                                title: "本地商品分类",
                                                name: "category_id",
                                                type: "treeSelect",
                                                placeholder: "请选择商品分类",
                                                dict: `category->owner=0,id,name,pid&tree=true`,
                                                required: true,
                                                parent: false
                                            },
                                            {
                                                title: "远端图片本地化",
                                                name: "image_download",
                                                type: "switch",
                                                tips: "启用后，导入对方商品时，会自动将对方所有图片资源下载至本地"
                                            },
                                            {
                                                title: "远端信息同步",
                                                name: "shared_sync",
                                                type: "switch",
                                                tips: "启用后，远端商品信息会实时同步本地，远端价发生变化会立即同步"
                                            },
                                            {
                                                title: "远端价格同步",
                                                name: "shared_amount_sync",
                                                type: "switch",
                                                tips: "启用后，远端的价格会实时同步本地商品"
                                            },
                                            {
                                                title: "远端配置同步",
                                                name: "shared_config_sync",
                                                type: "switch",
                                                tips: "启用后，远端的商品配置会实时同步本地商品（如种类，SKU）"
                                            },
                                            {
                                                title: "立即上架",
                                                name: "shelves",
                                                type: "switch",
                                                tips: "开启后，入库完毕后会立即上架"
                                            },
                                            {
                                                title: "加价模式",
                                                name: "premium_type",
                                                type: "radio",
                                                dict: [
                                                    {id: 0, name: "普通金额加价"},
                                                    {id: 1, name: "百分比加价(99%的人选择)"}
                                                ],
                                                default: 1,
                                                required: true
                                            },
                                            {
                                                title: "加价数额",
                                                name: "premium",
                                                type: "input",
                                                placeholder: "加价金额/百分比(小数代替)",
                                                required: true
                                            },
                                            {
                                                title: false,
                                                name: "auth",
                                                type: "custom",
                                                complete: (form, dom) => mountRemoteProductPicker(form, dom, remoteGroups)
                                            }
                                        ]
                                    }
                                ],
                                assign: {},
                                confirmText: `${importStartIcon} 开始入库`,
                                autoPosition: false,
                                width: "780px",
                                renderComplete: (unique, index) => {
                                    const $form = $('.' + unique);
                                    $form.find('input[name="premium"]').attr({
                                        inputmode: 'decimal',
                                        autocomplete: 'off'
                                    });
                                    const $layer = $(`#layui-layer${index}`);
                                    $layer.addClass('md-shared-store-import-popup');
                                    bindLayerFocus($layer, true);
                                    $importPopupLayer = $layer;
                                    importPopupResizeEvent = `.mdSharedStoreImportPopup${index}`;
                                    const body = $layer.children('.layui-layer-content').children('.layui-card-body').get(0);
                                    if (body && typeof ResizeObserver === 'function') {
                                        importPopupResizeObserver = new ResizeObserver(() => scheduleImportPopupFit());
                                        importPopupResizeObserver.observe(body);
                                        const form = body.querySelector(':scope > .layui-form');
                                        const picker = body.querySelector('.md-remote-product-picker');
                                        if (form) importPopupResizeObserver.observe(form);
                                        if (picker) importPopupResizeObserver.observe(picker);
                                    }
                                    $(window).on(`resize${importPopupResizeEvent}`, () => scheduleImportPopupFit());
                                    $layer.on(`click${importPopupResizeEvent}`, '.layui-layer-min, .layui-layer-max', () => {
                                        window.setTimeout(() => scheduleImportPopupFit(), 160);
                                    });
                                    scheduleImportPopupFit(true);
                                },
                                end: destroyImportPopupSizing
                            });
                        });
                    }
                }, {
                    icon: 'fa-duotone fa-regular fa-pen-to-square',
                    class: "text-primary",
                    click: (event, value, row, index) => {
                        modal(util.icon("fa-duotone fa-regular fa-pen-to-square me-1") + " 修改远端店铺", row);
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-trash-can',
                    class: "text-danger",
                    click: (event, value, row, index) => {
                        message.ask("您确定要移除此远端店铺吗，此操作无法恢复", () => {
                            if (!controllerActive) return;
                            const id = storeId(row?.id);
                            if (!id) {
                                message.error('店铺编号无效，请刷新页面后重试');
                                return;
                            }
                            util.post('/admin/api/store/del', {list: [id]}, res => {
                                if (!controllerActive) return;
                                message.success("删除成功");
                                table.refresh();
                            });
                        });
                    }
                },
                {
                    icon: 'fa-duotone fa-regular fa-earth-asia text-primary',
                    class: 'admin-mobile-operation-only text-primary',
                    title: '访问店铺',
                    show: row => mobileAdminEnabled() && Boolean(row.domain),
                    click: (event, value, row) => openExternal(row.domain)
                }
            ]
        },
    ]);
    table.setPagination(15, [15, 30]);
    table.setSearch([
        {title: '店铺名称', name: 'search-name', type: 'input'},
        {title: '店铺地址', name: 'search-domain', type: 'input'},
        {title: '协议', name: 'equal-type', type: 'select', dict: '_shared_type'}
    ]);

    table.onComplete((a, b, c) => {
        const generation = ++connectGeneration;
        c?.data?.list?.forEach(item => {
            const id = storeId(item?.id);
            if (!id) return;
            trackRequest($.post("/admin/api/store/connect", {id: id}))
                .done(run => {
                if (!controllerActive || generation !== connectGeneration) return;
                let ins = $(".connect-" + id);
                if (run.code == 200) {
                    item.__mobileConnectStatus = {success: true, message: "正常"};
                    ins.html(format.badge("正常", "a-badge-success"));
                    $(".items-" + id).show();
                } else {
                    const failure = plainMessage(run?.msg) || "连接失败";
                    item.__mobileConnectStatus = {success: false, message: failure};
                    ins.html(format.badge(escapeHtml(failure), "a-badge-danger"));
                }
                refreshMobile('store-connect');
                })
                .fail((xhr, status) => {
                    if (!controllerActive || generation !== connectGeneration || status === 'abort') return;
                    item.__mobileConnectStatus = {success: false, message: "连接请求失败"};
                    $(".connect-" + id).html(format.badge("连接请求失败", "a-badge-danger"));
                    refreshMobile('store-connect-error');
                });
        });
    });
    table.render();


    $('.btn-import-tasks').off(namespace).on('click' + namespace, function () {
        if (importTasks.size === 0) {
            layer.msg("暂无可恢复的入库任务");
            return;
        }
        importTasks.forEach(task => {
            openImportTaskLog(task);
            if (!['completed', 'stopped'].includes(task.status)) runImportTask(task);
        });
    });
    $('.btn-app-create').off(namespace).on('click' + namespace, function () {
        modal(`${util.icon("fa-duotone fa-regular fa-link")} 添加远端店铺`);
    });
    loadImportTasks();
    updateImportTaskButton();
    importTasks.forEach(task => {
        openImportTaskLog(task);
        if (!['completed', 'stopped'].includes(task.status)) runImportTask(task);
    });

    function destroy() {
        if (!controllerActive) return;
        controllerActive = false;
        connectGeneration++;
        _LogPid = null;
        clearTimeout(mobileRefreshTimer);
        mobileRefreshTimer = null;
        $('.btn-app-create').off(namespace);
        $('.btn-import-tasks').off(namespace);
        $(document).off('pjax:beforeReplace' + namespace);
        controllerRequests.forEach(request => {
            try { request.abort(); } catch (error) {}
        });
        controllerRequests.clear();
        controllerLayers.forEach(index => layer.close(index));
        controllerLayers.clear();
        if (table && !table.isDestroyed && typeof table.destroy === 'function') table.destroy();
        table = null;
        if (typeof Swal !== 'undefined') Swal.close();
        if (window.__mdSharedStoreDestroy === destroy) delete window.__mdSharedStoreDestroy;
    }

    window.__mdSharedStoreDestroy = destroy;
    $(document).off('pjax:beforeReplace' + namespace).one('pjax:beforeReplace' + namespace, destroy);
}();
