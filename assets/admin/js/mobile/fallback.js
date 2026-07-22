(function (window, document) {
    'use strict';

    var api = window.AdminMobile;
    if (!api) return;
    var snapshots = new Map();
    var localQueries = new Map();
    var treeCollapseStates = new Map();
    var mounted = false;
    var liveTextTimer = 0;
    var referenceAlignmentFrame = 0;
    var referenceAlignmentRoots = new Set();
    var textTemplate = document.createElement('template');
    var cardVariants = ['identity', 'transaction', 'product', 'audit', 'mapping'];

    function stopLiveTextTimer() {
        if (!liveTextTimer) return;
        window.clearInterval(liveTextTimer);
        liveTextTimer = 0;
    }

    function refreshLiveText() {
        var controls = Array.from(document.querySelectorAll('[data-admin-mobile-live-text]'));
        if (!controls.length) {
            stopLiveTextTimer();
            return;
        }
        if (document.hidden) return;
        var changedRoots = new Set();
        controls.forEach(function (control) {
            if (typeof control.__adminMobileLiveTextUpdate !== 'function') return;
            var before = control.textContent;
            try { control.__adminMobileLiveTextUpdate(); } catch (error) {}
            if (control.textContent === before) return;
            var root = control.closest && control.closest('[data-admin-mobile-table]');
            if (root) changedRoots.add(root);
        });
        changedRoots.forEach(alignReferenceCards);
    }

    function ensureLiveTextTimer() {
        if (liveTextTimer) return;
        liveTextTimer = window.setInterval(refreshLiveText, 1000);
    }

    function clearReferenceAlignment() {
        if (referenceAlignmentFrame) window.cancelAnimationFrame(referenceAlignmentFrame);
        referenceAlignmentFrame = 0;
        referenceAlignmentRoots.clear();
    }

    function alignReferenceCards(root) {
        if (!root || !root.querySelectorAll) return;
        referenceAlignmentRoots.add(root);
        if (referenceAlignmentFrame) return;
        referenceAlignmentFrame = window.requestAnimationFrame(function () {
            referenceAlignmentFrame = 0;
            var roots = Array.from(referenceAlignmentRoots);
            referenceAlignmentRoots.clear();
            roots.forEach(function (container) {
                if (!container.isConnected) return;
                container.querySelectorAll('.admin-mobile-data-card--reference > header > .admin-mobile-reference-prominent').forEach(function (prominent) {
                    var card = prominent.closest('.admin-mobile-data-card--reference');
                    if (!card) return;
                    prominent.style.setProperty('--admin-mobile-reference-offset-y', '0px');
                    var status = card.querySelector('.admin-mobile-card-status');
                    if (status && status.parentElement === card) status.style.removeProperty('padding-inline-end');
                    var value = prominent.querySelector('.admin-mobile-reference-prominent-value > strong') ||
                        prominent.querySelector('.admin-mobile-reference-prominent-value');
                    if (!value) return;
                    var cardRect = card.getBoundingClientRect();
                    var valueRect = value.getBoundingClientRect();
                    if (!cardRect.height || !valueRect.height) return;
                    var offset = (cardRect.top + cardRect.height / 2) - (valueRect.top + valueRect.height / 2);
                    if (status && status.parentElement === card && !status.hidden) {
                        var projectedTop = valueRect.top + offset;
                        var projectedBottom = valueRect.bottom + offset;
                        var collision = Array.from(status.children).some(function (badge) {
                            var badgeRect = badge.getBoundingClientRect();
                            return valueRect.left < badgeRect.right && valueRect.right > badgeRect.left &&
                                projectedTop < badgeRect.bottom && projectedBottom > badgeRect.top;
                        });
                        if (collision) {
                            var statusRect = status.getBoundingClientRect();
                            var reserve = Math.max(0, Math.ceil(statusRect.right - valueRect.left + 6));
                            status.style.setProperty('padding-inline-end', reserve + 'px');
                            prominent.style.setProperty('--admin-mobile-reference-offset-y', '0px');
                            cardRect = card.getBoundingClientRect();
                            valueRect = value.getBoundingClientRect();
                            offset = (cardRect.top + cardRect.height / 2) - (valueRect.top + valueRect.height / 2);
                        }
                    }
                    prominent.style.setProperty('--admin-mobile-reference-offset-y', (Math.round(offset * 100) / 100) + 'px');
                });
            });
        });
    }

    function cleanupToolbarProxies(root) {
        if (!root || !root.querySelectorAll) return;
        root.querySelectorAll('[data-admin-mobile-toolbar-proxy]').forEach(function (button) {
            if (button.__adminMobileSourceObserver) button.__adminMobileSourceObserver.disconnect();
            button.__adminMobileSourceObserver = null;
        });
    }

    function text(value) {
        // Template contents are inert: image/script event attributes cannot run
        // while we reduce legacy formatter HTML to plain mobile-card text.
        textTemplate.innerHTML = value == null ? '' : String(value);
        return (textTemplate.content.textContent || '').trim();
    }

    function emptyRelativeTimeValue(value) {
        var normalized = String(value == null ? '' : value).trim();
        return !normalized ||
            normalized === '-' ||
            /^0+(?:\.0+)?$/.test(normalized) ||
            /^0000-00-00(?:[ T]00:00(?::00(?:\.0+)?)?)?$/.test(normalized);
    }

    function relativeTimeTimestamp(value) {
        if (value instanceof Date) {
            var dateTimestamp = value.getTime();
            return Number.isFinite(dateTimestamp) ? dateTimestamp : null;
        }
        var normalized = String(value == null ? '' : value).trim();
        if (emptyRelativeTimeValue(normalized)) return null;
        if (/^\d{10}$/.test(normalized)) return Number(normalized) * 1000;
        if (/^\d{13}$/.test(normalized)) return Number(normalized);

        var local = normalized.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2})(?:\.(\d{1,6}))?)?)?$/);
        if (local) {
            var parts = local.slice(1, 7).map(function (part) { return part === undefined ? 0 : Number(part); });
            var milliseconds = Number(String(local[7] || '').padEnd(3, '0').slice(0, 3)) || 0;
            var timestamp = Date.UTC(parts[0], parts[1] - 1, parts[2], parts[3] - 8, parts[4], parts[5], milliseconds);
            var check = new Date(timestamp + 8 * 60 * 60 * 1000);
            if (
                check.getUTCFullYear() !== parts[0] ||
                check.getUTCMonth() !== parts[1] - 1 ||
                check.getUTCDate() !== parts[2] ||
                check.getUTCHours() !== parts[3] ||
                check.getUTCMinutes() !== parts[4] ||
                check.getUTCSeconds() !== parts[5] ||
                check.getUTCMilliseconds() !== milliseconds
            ) return null;
            return timestamp;
        }

        if (/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+\-]\d{2}:\d{2})$/i.test(normalized)) {
            var parsed = Date.parse(normalized.replace(' ', 'T'));
            return Number.isFinite(parsed) ? parsed : null;
        }
        return null;
    }

    function relativeTimeFromTimestamp(timestamp) {
        var difference = Date.now() - timestamp;
        var future = difference < 0;
        var seconds = Math.floor(Math.abs(difference) / 1000);
        if (seconds < 5) return '刚刚';
        var value;
        var unit;
        if (seconds < 60) {
            value = seconds;
            unit = '秒';
        } else if (seconds < 60 * 60) {
            value = Math.floor(seconds / 60);
            unit = '分钟';
        } else if (seconds < 24 * 60 * 60) {
            value = Math.floor(seconds / (60 * 60));
            unit = '小时';
        } else if (seconds < 30 * 24 * 60 * 60) {
            value = Math.floor(seconds / (24 * 60 * 60));
            unit = '天';
        } else if (seconds < 365 * 24 * 60 * 60) {
            value = Math.floor(seconds / (30 * 24 * 60 * 60));
            unit = '个月';
        } else {
            value = Math.floor(seconds / (365 * 24 * 60 * 60));
            unit = '年';
        }
        return value + unit + (future ? '后' : '前');
    }

    function formatRelativeTime(value) {
        var normalized = String(value == null ? '' : value).trim();
        if (emptyRelativeTimeValue(normalized)) return '-';
        var timestamp = relativeTimeTimestamp(value);
        if (Number.isFinite(timestamp)) return relativeTimeFromTimestamp(timestamp);

        var replaced = normalized.replace(
            /\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+\-]\d{2}:\d{2})?/gi,
            function (candidate) {
                if (emptyRelativeTimeValue(candidate)) return '-';
                var candidateTimestamp = relativeTimeTimestamp(candidate);
                return Number.isFinite(candidateTimestamp) ? relativeTimeFromTimestamp(candidateTimestamp) : candidate;
            }
        );
        return replaced || '-';
    }

    function relativeTimeEnabled(recipe, pageRecipe) {
        var signatures = [recipe, pageRecipe].filter(Boolean).map(function (candidate) {
            return [candidate.id, candidate.workflow, candidate.pageType].filter(Boolean).join(' ').toLowerCase();
        });
        if (!signatures.some(function (signature) { return /(?:^|\s)admin-/.test(signature); })) return false;
        return !signatures.some(function (signature) {
            if (/(?:^|\s)admin-(?:store(?:-|$)|license-transfer(?:-|$))/.test(signature)) return true;
            if (/(?:^|[-\s])plugins?(?:-|$|\s)/.test(signature)) return true;
            return /(?:^|\s)(?:admin-)?(?:supply-market|third-dock)(?:-|$|\s)/.test(signature);
        });
    }

    function relativeTimeDefinition(recipe, definition, pageRecipe) {
        if (!definition || !relativeTimeEnabled(recipe, pageRecipe) || definition.relativeTime === false) return false;
        if (definition.relativeTime === true) return true;
        var fieldName = String(definition.field || '').toLowerCase().split('.').pop();
        var label = String(definition.label || definition.title || '');
        return /(?:^|_)(?:time|date|datetime|timestamp|at)$/.test(fieldName) ||
            /(?:时间|日期|到期|最后动态|最近登录|上次登录)/.test(label);
    }

    function relativeTimeText(value) {
        return /(?:刚刚|\d+(?:秒|分钟|小时|天|个月|年)[前后])/.test(String(value || ''));
    }

    function sanitizeTree(root) {
        if (!root || !root.querySelectorAll) return root;
        root.querySelectorAll('script, iframe, object, embed, link, meta, base').forEach(function (node) { node.remove(); });
        var nodes = Array.from(root.querySelectorAll('*'));
        if (root.nodeType === 1) nodes.unshift(root);
        nodes.forEach(function (node) {
            Array.from(node.attributes || []).forEach(function (attribute) {
                var name = String(attribute.name || '').toLowerCase();
                var value = String(attribute.value || '').trim();
                if (/^on/.test(name) || name === 'srcdoc' || name === 'formaction' || name === 'action') {
                    node.removeAttribute(attribute.name);
                    return;
                }
                if (['href', 'src', 'xlink:href', 'data'].indexOf(name) >= 0 && /^(?:javascript|vbscript):/i.test(value)) {
                    node.removeAttribute(attribute.name);
                    return;
                }
                if (name === 'src' && /^data:/i.test(value) && !/^data:image\/(?:png|gif|jpe?g|webp|avif);/i.test(value)) {
                    node.removeAttribute(attribute.name);
                }
            });
        });
        return root;
    }

    function rawValue(row, field) {
        return String(field || '').split('.').reduce(function (value, part) {
            return value == null ? value : value[part];
        }, row);
    }

    function visibleColumns(snapshot) {
        return (snapshot.columns || []).filter(function (column) {
            return column && column.visible !== false && column.field && column.type !== 'button' && !column.checkbox && !column.radio;
        });
    }

    function detailColumns(snapshot) {
        return (snapshot.columns || []).filter(function (column) {
            return column && column.field && column.field !== 'detail_view' && column.type !== 'button' && !Array.isArray(column.buttons) && !column.checkbox && !column.radio;
        });
    }

    function displayValue(snapshot, column, row, index) {
        var value;
        if (snapshot && typeof snapshot.displayValue === 'function') {
            try { value = snapshot.displayValue(column, row, index); } catch (error) {}
        }
        if (value === undefined) value = rawValue(row, column && column.field);
        value = text(value == null || value === '' ? '-' : value);
        return value || '-';
    }

    function recipeFields(recipe, name) {
        if (!recipe) return [];
        var value = recipe[name];
        if (name === 'primary' && value && !Array.isArray(value)) value = value.fields || [value.field];
        return (Array.isArray(value) ? value : value ? [value] : []).map(function (item) { return typeof item === 'string' ? item : item.field; }).filter(Boolean);
    }

    function recipeDefinitions(recipe, name) {
        if (!recipe) return [];
        var value = recipe[name];
        if (name === 'primary' && value && !Array.isArray(value)) {
            if (Array.isArray(value.fields)) return value.fields.map(function (field) { return typeof field === 'string' ? {field: field, label: value.label} : field; });
            value = [value];
        }
        return (Array.isArray(value) ? value : value ? [value] : []).map(function (item) {
            return typeof item === 'string' ? {field: item, label: item} : item;
        }).filter(function (item) { return item && item.field; });
    }

    function recipeVariant(recipe) {
        var pageType = String(recipe && recipe.pageType || '').toLowerCase();
        var signature = [recipe && recipe.id, recipe && recipe.workflow, pageType].filter(Boolean).join(' ').toLowerCase();
        if (pageType === 'mapping-list' || pageType === 'tree-list' || /(?:third-dock-(?:site|class|good|rule)|category|mapping)/.test(signature)) return 'mapping';
        if (pageType === 'order-list' || /(?:order|recharge|bill|cash|coupon|payment|transaction)/.test(signature)) return 'transaction';
        if (pageType === 'audit-list' || /(?:ticket|message|audit|review|(?:^|[-_ ])log(?:$|[-_ ]))/.test(signature)) return 'audit';
        if (['catalog-list', 'inventory-list', 'media-list', 'store-list'].indexOf(pageType) >= 0 || /(?:commodity|product|inventory|card|plugin|store|application|file|media|supply)/.test(signature)) return 'product';
        return 'identity';
    }

    function recipeMetricLimit(recipe) {
        var limit = Number(recipe && recipe.metricLimit);
        return Number.isFinite(limit) ? Math.min(Math.max(Math.floor(limit), 1), 8) : 4;
    }

    function recipeStatusLimit(recipe) {
        var limit = Number(recipe && recipe.statusLimit);
        return Number.isFinite(limit) ? Math.min(Math.max(Math.floor(limit), 1), 8) : 3;
    }

    function recipeCompactLimit(recipe) {
        var limit = Number(recipe && recipe.compactLimit);
        return Number.isFinite(limit) ? Math.min(Math.max(Math.floor(limit), 1), 6) : 4;
    }

    function recipeCompactMetricLimit(recipe) {
        var limit = Number(recipe && recipe.compactMetricLimit);
        return Number.isFinite(limit) ? Math.min(Math.max(Math.floor(limit), 0), 4) : 2;
    }

    function compactCardDefinitions(recipe, statusDefinitions, metricDefinitions, options) {
        var definitions = [];
        var fields = Object.create(null);
        var reference = options && options.reference === true;
        var prominent = options && options.prominent;
        var add = function (definition, metric) {
            if (!definition || definition.compact === false || definition.fullWidth === true) return;
            var fieldName = String(definition.field || '');
            if (metric && /(?:^|[._-])(?:description|content|message|html|secret|widget|domain|url)$/i.test(fieldName)) return;
            var key = fieldName || String(definition.label || definitions.length);
            if (fields[key]) return;
            fields[key] = true;
            var compact = Object.assign({}, definition);
            compact.__adminMobileMetric = metric === true;
            if (metric && compact.dot === undefined) compact.dot = false;
            definitions.push(compact);
        };
        var addMetrics = function () {
            metricDefinitions.slice(0, Math.min(recipeMetricLimit(recipe), recipeCompactMetricLimit(recipe))).forEach(function (definition) { add(definition, true); });
        };
        var addStatuses = function () {
            statusDefinitions.slice(0, recipeStatusLimit(recipe)).forEach(function (definition) { add(definition, false); });
        };
        if (reference) {
            addStatuses();
            addMetrics();
        } else {
            addMetrics();
            addStatuses();
        }
        return definitions.slice(0, recipeCompactLimit(recipe) + (reference && prominent ? 1 : 0));
    }

    function referenceCardEnabled(recipe, pageRecipe) {
        var candidates = [recipe, pageRecipe].filter(Boolean);
        if (!candidates.length) return true;
        return !candidates.some(function (candidate) {
            if (candidate.referenceCard === false || candidate.cardLayout === 'ledger') return true;
            var id = String(candidate.id || '').toLowerCase();
            if (['admin-store-home', 'admin-store-developer', 'admin-license-transfer'].indexOf(id) >= 0) return true;
            if (/(?:^|-)plugin(?:-|$)/.test(id)) return true;
            return /^(?:supply-market|third-dock)(?:-|$)/.test(id);
        });
    }

    function prominentFieldSignature(definition) {
        return [definition && definition.field, definition && definition.label].filter(Boolean).join(' ').toLowerCase();
    }

    function referenceProminentDefinition(recipe, statusDefinitions, metricDefinitions) {
        if (recipe && recipe.referenceProminent === false) return null;
        var explicit = recipeDefinitions(recipe, 'prominent')[0];
        if (explicit) return explicit;
        var metric = (metricDefinitions || []).find(function (definition) {
            return definition && definition.fullWidth !== true && definition.prominent !== false &&
                !/(?:description|content|message|html|secret|widget|domain|url)/i.test(prominentFieldSignature(definition));
        });
        if (metric) return metric;
        return (statusDefinitions || []).find(function (definition) {
            return /(?:amount|money|price|balance|cost|fee|total|stock|count|quantity|num|recharge|coin|金额|价格|余额|手续费|库存|数量|次数|面值|元气|硬币)/i.test(prominentFieldSignature(definition));
        }) || null;
    }

    function statusBadgeForDefinition(status, definition, source) {
        if (!status || !definition) return null;
        var fieldName = String(definition.field || '');
        return Array.from(status.children).find(function (badge) {
            return badge.getAttribute('data-admin-mobile-field') === fieldName &&
                (!source || badge.getAttribute('data-admin-mobile-source') === source);
        }) || null;
    }

    function referenceValueNeedsLabel(value) {
        return !/^(?:[+\-]?\s*[￥¥$€£]|(?:cny|rmb|usd|usdt)\b)/i.test(String(value || '').trim());
    }

    function disambiguateReferenceBadges(status) {
        if (!status) return;
        var badges = Array.from(status.children);
        var counts = Object.create(null);
        badges.forEach(function (badge) {
            var span = badge.querySelector('span');
            var value = span ? span.textContent.trim() : '';
            if (value) counts[value] = (counts[value] || 0) + 1;
        });
        badges.forEach(function (badge) {
            var span = badge.querySelector('span');
            var value = span ? span.textContent.trim() : '';
            var label = String(badge.title || '').trim();
            if (!value || counts[value] < 2 || !label || value.indexOf(label + ' ') === 0) return;
            span.textContent = label + ' ' + value;
        });
    }

    function renderReferenceCard(card, recipe, snapshot, columns, row, index, moreControl, statusDefinitions, metricDefinitions, referenceEnabled, configuredProminent) {
        if (!card || !moreControl || !referenceEnabled) return;
        var header = card.querySelector('header');
        var status = card.querySelector('.admin-mobile-card-status');
        if (!header || !status) return;

        card.classList.add('admin-mobile-data-card--reference');
        card.setAttribute('data-admin-mobile-card-layout', 'reference');
        disambiguateReferenceBadges(status);

        var prominentDefinition = configuredProminent || referenceProminentDefinition(recipe, statusDefinitions, metricDefinitions);
        var prominentValue = prominentDefinition ? definitionValue(snapshot, prominentDefinition, columns, row, index, recipe) : '';
        if (!prominentValue || prominentValue === '-') prominentDefinition = null;

        var prominentBadge = prominentDefinition
            ? statusBadgeForDefinition(status, prominentDefinition, metricDefinitions.indexOf(prominentDefinition) >= 0 ? 'metric' : '')
            : null;
        if (prominentBadge) prominentBadge.remove();

        var statusDefinition = recipe && recipe.referenceProminentStatus === true
            ? (statusDefinitions || []).find(function (definition) {
                return definition !== prominentDefinition && Boolean(statusBadgeForDefinition(status, definition, 'status'));
            })
            : null;
        var statusBadge = statusBadgeForDefinition(status, statusDefinition, 'status');

        if (prominentDefinition || statusBadge) {
            var prominent = document.createElement('div');
            prominent.className = 'admin-mobile-reference-prominent';
            if (!prominentDefinition && statusBadge) {
                card.classList.add('admin-mobile-data-card--reference-status-only');
                prominent.classList.add('admin-mobile-reference-prominent--status-only');
            }
            if (prominentDefinition) {
                var valueLine = document.createElement('div');
                valueLine.className = 'admin-mobile-reference-prominent-value';
                if (prominentDefinition.prominentLabel !== false && referenceValueNeedsLabel(prominentValue)) {
                    var valueLabel = document.createElement('small');
                    valueLabel.textContent = text(prominentDefinition.label || prominentDefinition.field);
                    valueLine.appendChild(valueLabel);
                }
                var value = document.createElement('strong');
                value.textContent = prominentValue;
                var tone = definitionTone(prominentDefinition, prominentValue, row, index, snapshot, function () { return 'success'; });
                valueLine.setAttribute('data-admin-mobile-tone', tone);
                valueLine.appendChild(value);
                if (relativeTimeDefinition(recipe, prominentDefinition, snapshot && snapshot.__adminMobilePageRecipe) && relativeTimeText(prominentValue)) {
                    bindLiveTextControl(value, function () {
                        return definitionValue(snapshot, prominentDefinition, columns, row, index, recipe);
                    });
                }
                prominent.appendChild(valueLine);
            }
            if (statusBadge) prominent.appendChild(statusBadge);
            header.insertBefore(prominent, moreControl);
        } else {
            card.classList.add('admin-mobile-data-card--reference-simple');
        }

        status.hidden = status.children.length < 1;
        card.classList.toggle('admin-mobile-data-card--reference-has-status', !status.hidden);
        var moreIcon = moreControl.querySelector('.material-icons-outlined');
        if (moreIcon) moreIcon.textContent = 'chevron_right';
        moreControl.classList.add('admin-mobile-reference-more');
    }

    function applyCardVariant(element, baseClass, variant) {
        cardVariants.forEach(function (name) { element.classList.remove(baseClass + '--' + name); });
        element.classList.add(baseClass + '--' + variant);
        element.setAttribute('data-admin-mobile-card-variant', variant);
    }

    function mediaDefinition(recipe, row) {
        var configured = recipe && recipe.media;
        if (Array.isArray(configured)) configured = configured[0];
        if (configured) return typeof configured === 'string' ? {field: configured} : configured;
        var candidate = ['avatar', 'cover', 'image', 'icon', 'logo', 'thumb', 'thumbnail'].find(function (field) {
            return rawValue(row, field);
        });
        return candidate ? {field: candidate} : null;
    }

    function mediaFields(definition) {
        if (!definition) return [];
        var fields = definition.fields || definition.field || [];
        return (Array.isArray(fields) ? fields : [fields]).filter(Boolean);
    }

    function mediaSource(value) {
        if (Array.isArray(value)) value = value[0];
        if (value && typeof value === 'object') value = value.url || value.src || value.path || value.avatar || value.image;
        if (typeof value !== 'string') return '';
        value = value.trim();
        return /^(?:data:image\/|blob:|https?:\/\/|\/\/|\/)/i.test(value) ? value : '';
    }

    function mediaDescriptorSource(definition, row, snapshot, columns, index) {
        var source = '';
        mediaFields(definition).some(function (field) {
            var value = rawValue(row, field);
            source = definition && definition.type === 'payment' ? rechargePaymentIcon(value) : mediaSource(value);
            return Boolean(source);
        });
        return source;
    }

    function mediaIcon(recipe) {
        if (recipe && recipe.media && recipe.media.fallbackIcon) return recipe.media.fallbackIcon;
        var type = (recipe && [recipe.id, recipe.pageType, recipe.workflow].filter(Boolean).join(' ')) || '';
        if (/user|member|manager/i.test(type)) return 'person';
        if (/commodity|product|catalog|inventory|card/i.test(type)) return 'inventory_2';
        if (/order|bill|cash|payment|trade/i.test(type)) return 'receipt_long';
        if (/ticket|message/i.test(type)) return 'chat_bubble_outline';
        if (/plugin|store|application/i.test(type)) return 'widgets';
        if (/file|media/i.test(type)) return 'insert_drive_file';
        return 'data_object';
    }

    function enableMediaPreview(media, source, label) {
        if (!media || !source || typeof component === 'undefined' || !component || typeof component.previewImage !== 'function') return;
        var open = function (event) {
            if (event.type === 'keydown' && event.key !== 'Enter' && event.key !== ' ') return;
            event.preventDefault();
            event.stopPropagation();
            component.previewImage(source);
        };
        media.classList.add('is-previewable');
        media.setAttribute('role', 'button');
        media.setAttribute('tabindex', '0');
        media.setAttribute('aria-label', '预览' + (label || '当前记录') + '图片');
        media.addEventListener('click', open);
        media.addEventListener('keydown', open);
    }

    function statusTone(value) {
        value = String(value || '').trim();
        if (/(?:未锁定|未冻结|未过期)/i.test(value)) return 'success';
        if (/^(?:锁定|冻结|过期)$/i.test(value) || /(?:已锁定|锁定中|已冻结|冻结中|已过期|locked|frozen|expired)/i.test(value)) return 'danger';
        if (/(?:(?:未|不|非)(?:启用|完成|开启|上线|上架|通过|支付|处理|正常|成功|在线|运行)|停用|关闭|失败|禁用|删除|拒绝|驳回|异常|下架|离线|disabled|inactive|failed|failure|closed|offline|error)/i.test(value)) return 'danger';
        if (/(?:等待|待处理|待审核|待支付|审核中|进行中|处理中|待启用|待发货|未发货|部分|pending|processing|review)/i.test(value)) return 'warning';
        if (/(?:成功|正常|启用|完成|开启|在线|运行中|上架|通过|已支付|已处理|已发货|enabled|active|running|success|completed|online|approved)/i.test(value)) return 'success';
        return 'neutral';
    }

    function definitionTone(definition, value, row, index, snapshot, fallback) {
        var tone = definition && definition.tone;
        if (typeof tone === 'function') {
            try { tone = tone(value, row, index, snapshot); } catch (error) { tone = ''; }
        }
        if (tone && typeof tone === 'object') tone = tone[value] === undefined ? tone.default : tone[value];
        tone = String(tone || '').trim().toLowerCase();
        var aliases = {error: 'danger', critical: 'danger', positive: 'success', pending: 'warning', default: 'neutral'};
        tone = aliases[tone] || tone;
        if (/^[a-z][a-z0-9_-]*$/.test(tone)) return tone;
        return typeof fallback === 'function' ? fallback(value) : 'neutral';
    }

    function cardSubtitle(recipe, snapshot, columns, row, index, primaryDefinitions, heading) {
        var configured = recipeDefinitions(recipe, 'subtitle')[0];
        if (configured) return definitionValue(snapshot, configured, columns, row, index, recipe);
        if (primaryDefinitions.length > 1) return primaryDefinitionValue(snapshot, primaryDefinitions[1], columns, row, index, recipe);
        var details = recipeDefinitions(recipe, 'details');
        var preferred = ['id', 'email', 'create_time', 'user', 'owner'];
        var parts = [];
        var labelSeparator = relativeTimeEnabled(recipe, snapshot && snapshot.__adminMobilePageRecipe) ? ' · ' : ' ';
        preferred.some(function (field) {
            var definition = details.find(function (item) { return item.field === field; });
            if (!definition && rawValue(row, field) !== undefined) definition = {field: field, label: field === 'id' ? 'ID' : ''};
            if (!definition || recipeFields(recipe, 'primary').indexOf(definition.field) >= 0) return false;
            var value = definitionValue(snapshot, definition, columns, row, index, recipe);
            if (!value || value === '-' || value === heading) return false;
            parts.push((definition.label ? text(definition.label) + labelSeparator : '') + value);
            return parts.length >= 2;
        });
        return parts.join(' · ') || text((recipe && recipe.primary && recipe.primary.label) || '记录');
    }

    function bindLiveTextControl(control, update) {
        if (typeof update !== 'function') return;
        control.setAttribute('data-admin-mobile-live-text', '');
        control.__adminMobileLiveTextUpdate = function () {
            var updated = text(update() || '');
            if (control.textContent !== updated) control.textContent = updated;
        };
        control.__adminMobileLiveTextUpdate();
        ensureLiveTextTimer();
    }

    function bindLiveSubtitleParts(control, definition, recipe, snapshot, row, emphasis, label, liveUpdate) {
        var updateParts = typeof definition.liveParts === 'function'
            ? function () {
                return definition.liveParts(row, {
                    emphasis: emphasis.textContent,
                    text: label.textContent
                });
            }
            : (relativeTimeDefinition(recipe, definition, snapshot && snapshot.__adminMobilePageRecipe) && typeof liveUpdate === 'function' && typeof definition.parts === 'function'
                ? function () { return definition.parts(liveUpdate(), row); }
                : null);
        if (!updateParts) return;
        if (!relativeTimeText(emphasis.textContent + ' ' + label.textContent)) return;
        control.setAttribute('data-admin-mobile-live-text', '');
        control.__adminMobileLiveTextUpdate = function () {
            var updated = updateParts();
            if (!updated) return;
            var nextEmphasis = text(updated.emphasis || '');
            var nextLabel = text(updated.text || '');
            if (emphasis.textContent !== nextEmphasis) emphasis.textContent = nextEmphasis;
            if (label.textContent !== nextLabel) label.textContent = nextLabel;
        };
        control.__adminMobileLiveTextUpdate();
        ensureLiveTextTimer();
    }

    function renderCardSubtitle(control, recipe, snapshot, row, subtitle, liveUpdate) {
        control.textContent = subtitle || '';
        var definition = recipeDefinitions(recipe, 'subtitle')[0];
        if (!subtitle || subtitle === '-') return;
        if (!definition) {
            if (relativeTimeText(subtitle)) bindLiveTextControl(control, liveUpdate);
            return;
        }
        if (typeof definition.liveText === 'function') {
            if (relativeTimeText(subtitle)) bindLiveTextControl(control, function () { return definition.liveText(row); });
            return;
        }
        if (typeof definition.parts !== 'function') {
            if (
                relativeTimeDefinition(recipe, definition, snapshot && snapshot.__adminMobilePageRecipe) &&
                relativeTimeText(subtitle)
            ) bindLiveTextControl(control, liveUpdate);
            return;
        }
        var parts;
        try { parts = definition.parts(subtitle, row); } catch (error) { return; }
        if (!parts || (!parts.emphasis && !parts.text)) return;
        control.textContent = '';
        control.classList.add('admin-mobile-card-subtitle-line');
        var emphasis = document.createElement('strong');
        emphasis.textContent = text(parts.emphasis || '');
        var label = document.createElement('span');
        label.textContent = text(parts.text || '');
        if (definition.copyField) {
            var copyValue = rawValue(row, definition.copyField);
            copyValue = copyValue == null ? '' : String(copyValue).trim();
            var reference = document.createElement('span');
            reference.className = 'admin-mobile-card-subtitle-reference';
            control.classList.add('admin-mobile-card-subtitle-line--stacked-copy');
            reference.appendChild(emphasis);
            if (copyValue) {
                var copyButton = document.createElement('button');
                copyButton.type = 'button';
                copyButton.className = 'admin-mobile-card-subtitle-copy';
                copyButton.setAttribute('aria-label', text(definition.copyLabel || '复制内容') + ' ' + copyValue);
                copyButton.innerHTML = '<span class="material-icons-outlined" aria-hidden="true">content_copy</span>';
                copyButton.addEventListener('click', function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    copyConfiguredField({
                        copyField: definition.copyField,
                        label: definition.copyLabel || '复制内容'
                    }, row);
                });
                reference.appendChild(copyButton);
            }
            label.className = 'admin-mobile-card-subtitle-owner';
            control.append(reference, label);
            bindLiveSubtitleParts(control, definition, recipe, snapshot, row, emphasis, label, liveUpdate);
            return;
        }
        control.append(emphasis, label);
        bindLiveSubtitleParts(control, definition, recipe, snapshot, row, emphasis, label, liveUpdate);
    }

    function rechargePaymentIcon(value) {
        var configuredIcon = '';
        if (value && typeof value === 'object') {
            configuredIcon = mediaSource(value.icon || value.logo || value.image);
            value = value.name || value.title || value.code || '';
        }
        var payment = String(value || '').trim();
        if (/usdt/i.test(payment)) return '/assets/common/images/usdt.png';
        if (/(?:支付宝|alipay)/i.test(payment)) return '/assets/common/images/alipay.png';
        if (/(?:微信|wechat)/i.test(payment)) return '/assets/user/images/cash/wechat.png';
        return configuredIcon;
    }

    function renderLedgerCard(card, recipe, snapshot, columns, row, index, moreControl) {
        if (!card || !recipe || recipe.cardLayout !== 'ledger') return;
        var prominent = recipeDefinitions(recipe, 'prominent')[0];
        if (!prominent || !moreControl) return;

        card.classList.add('admin-mobile-data-card--ledger');
        card.setAttribute('data-admin-mobile-card-layout', 'ledger');

        var header = card.querySelector('header');
        var heading = card.querySelector('.admin-mobile-card-heading');
        var status = card.querySelector('.admin-mobile-card-status');
        var media = card.querySelector('.admin-mobile-card-media');
        var paymentIcon = rechargePaymentIcon(rawValue(row, 'pay'));
        if (media && paymentIcon) {
            var iconImage = document.createElement('img');
            iconImage.alt = '';
            iconImage.src = paymentIcon;
            media.querySelectorAll('img').forEach(function (image) { image.remove(); });
            media.appendChild(iconImage);
            media.classList.add('has-image', 'admin-mobile-card-media--payment');
        }

        var content = document.createElement('div');
        content.className = 'admin-mobile-ledger-content';
        if (header && heading) {
            header.insertBefore(content, heading);
            content.appendChild(heading);
        }

        var tradeNumber = String(rawValue(row, 'trade_no') || '').trim();
        if (tradeNumber) {
            var reference = document.createElement('div');
            reference.className = 'admin-mobile-ledger-reference';
            var referenceLabel = document.createElement('span');
            referenceLabel.textContent = '订单号';
            var referenceValue = document.createElement('span');
            referenceValue.textContent = tradeNumber;
            var copyButton = document.createElement('button');
            copyButton.type = 'button';
            copyButton.className = 'admin-mobile-ledger-copy';
            copyButton.setAttribute('aria-label', '复制订单号 ' + tradeNumber);
            copyButton.innerHTML = '<span class="material-icons-outlined" aria-hidden="true">content_copy</span>';
            copyButton.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                copyConfiguredField({copyField: 'trade_no', label: '复制订单号'}, row);
            });
            reference.append(referenceLabel, referenceValue, copyButton);
            content.appendChild(reference);
        }

        var amount = document.createElement('div');
        amount.className = 'admin-mobile-ledger-amount';
        amount.setAttribute('aria-label', text(prominent.label || prominent.field));
        var amountValue = document.createElement('strong');
        amountValue.textContent = definitionValue(snapshot, prominent, columns, row, index, recipe);
        if (
            relativeTimeDefinition(recipe, prominent, snapshot && snapshot.__adminMobilePageRecipe) &&
            relativeTimeText(amountValue.textContent)
        ) {
            bindLiveTextControl(amountValue, function () {
                return definitionValue(snapshot, prominent, columns, row, index, recipe);
            });
        }
        amount.appendChild(amountValue);
        if (status && status.firstElementChild) amount.appendChild(status.firstElementChild);
        if (status) status.remove();
        if (header) header.insertBefore(amount, moreControl);
        var moreIcon = moreControl.querySelector('.material-icons-outlined');
        if (moreIcon) moreIcon.textContent = 'chevron_right';
        moreControl.classList.add('admin-mobile-ledger-more');
    }

    function localSearchAvailable(snapshot, recipe) {
        if (!snapshot || !Array.isArray(snapshot.rows) || snapshot.rows.length < 2) return false;
        return ['dashboard', 'document', 'form'].indexOf(recipe && recipe.pageType) < 0;
    }

    function configureShellSearch(snapshot, recipe) {
        if (!api.shell || typeof api.shell.setSearch !== 'function') return;
        var hasSearch = snapshot.search && snapshot.search.instance;
        var hasState = snapshot.state && snapshot.state.options && snapshot.state.options.length;
        var hasLocalSearch = !hasSearch && !hasState && localSearchAvailable(snapshot, recipe);
        if (!hasSearch && !hasState && !hasLocalSearch) return;
        var count = snapshotFilterCount(snapshot);
        if (hasLocalSearch && localQueries.get(snapshot.id)) count += 1;
        var activeRecipe = typeof api.getActiveRecipe === 'function' ? api.getActiveRecipe() : null;
        api.shell.setSearch({
            source: 'table:' + snapshot.id,
            priority: activeRecipe && recipe && activeRecipe.id === recipe.id ? 20 : 10,
            placeholder: '搜索' + ((recipe && recipe.title) || '当前列表'),
            count: count,
            run: function () { if (hasLocalSearch) openLocalFilter(snapshot, recipe); else openFilters(snapshot); }
        });
    }

    function renderStoreDiscovery(host, snapshot, recipe) {
        if (!host || !recipe || recipe.id !== 'admin-store-home') return;
        var search = snapshot.search && snapshot.search.instance;
        var state = snapshot.state;
        if (!search && !(state && state.options && state.options.length)) return;

        var discovery = document.createElement('section');
        discovery.className = 'admin-mobile-store-discovery';
        discovery.setAttribute('aria-label', '应用搜索与类型筛选');

        var count = snapshotFilterCount(snapshot);
        var trigger = document.createElement('button');
        trigger.type = 'button';
        trigger.className = 'admin-mobile-search';
        trigger.setAttribute('aria-label', count ? '搜索应用，已启用 ' + count + ' 个筛选条件' : '搜索应用');
        trigger.innerHTML = '<span class="material-icons-outlined admin-mobile-search__lead" aria-hidden="true">search</span>' +
            '<span class="admin-mobile-search__copy">搜索应用</span>' +
            '<span class="admin-mobile-search__count"' + (count ? '' : ' hidden') + '>' + (count > 99 ? '99+' : count) + '</span>' +
            '<span class="material-icons-outlined admin-mobile-search__trail" aria-hidden="true">tune</span>';
        trigger.addEventListener('click', function () { openFilters(snapshot); });
        discovery.appendChild(trigger);

        host.appendChild(discovery);
    }

    function columnFor(columns, field) {
        return columns.find(function (column) { return column.field === field; });
    }

    function mobileColumnDefinition(column) {
        return column ? {
            field: column.field,
            label: text(column.title || column.field),
            __mobileColumn: true
        } : null;
    }

    function definitionValue(snapshot, definition, columns, row, index, recipe) {
        var column = columnFor(columns, definition.field);
        var customDefinition = definition.__mobileColumn !== true && (typeof definition.formatter === 'function' || Object.prototype.hasOwnProperty.call(definition, 'dict'));
        var value;
        if ((customDefinition || !column) && snapshot.detail && typeof snapshot.detail.displayValue === 'function') {
            try { value = snapshot.detail.displayValue(definition, row, index); } catch (error) {}
        }
        if (value === undefined) value = column ? displayValue(snapshot, column, row, index) : rawValue(row, definition.field);
        if (typeof definition.formatter === 'function' && (!snapshot.detail || typeof snapshot.detail.displayValue !== 'function')) {
            try { value = definition.formatter(value, row, index); } catch (error) {}
        }
        if (typeof definition.format === 'function') {
            try { value = definition.format(value, row, index, snapshot); } catch (error) {}
        }
        if (relativeTimeDefinition(recipe, definition, snapshot && snapshot.__adminMobilePageRecipe)) value = formatRelativeTime(value);
        value = text(value == null || value === '' ? '-' : value);
        return value || '-';
    }

    function primaryDefinitionValue(snapshot, definition, columns, row, index, recipe) {
        var value = rawValue(row, definition && definition.field);
        var hasCustomPresentation = definition && (typeof definition.format === 'function' || typeof definition.formatter === 'function' || Object.prototype.hasOwnProperty.call(definition, 'dict'));
        if (!hasCustomPresentation && value !== undefined && value !== null && typeof value !== 'object') {
            value = text(value === '' ? '-' : value);
            if (relativeTimeDefinition(recipe, definition, snapshot && snapshot.__adminMobilePageRecipe)) value = formatRelativeTime(value);
            return value || '-';
        }
        return definitionValue(snapshot, definition, columns, row, index, recipe);
    }

    function setDefinitionInlineContent(target, definition, row, fallback) {
        if (!target) return;
        var source = definition && rawValue(row, definition.field);
        var inlineRenderer = typeof component !== 'undefined' && component && typeof component.sanitizeInlineHtml === 'function'
            ? component
            : null;
        if (definition && definition.inlineHtml === true && source != null && inlineRenderer) {
            var safe = inlineRenderer.sanitizeInlineHtml(source);
            var template = document.createElement('template');
            template.innerHTML = safe;
            template.content.querySelectorAll('a').forEach(function (link) {
                while (link.firstChild) link.parentNode.insertBefore(link.firstChild, link);
                link.remove();
            });
            template.content.querySelectorAll('br').forEach(function (breakNode) {
                breakNode.replaceWith(document.createTextNode(' '));
            });
            if ((template.content.textContent || '').trim()) {
                target.replaceChildren(template.content.cloneNode(true));
                return;
            }
        }
        target.textContent = fallback || '-';
    }

    function clickSelector(selector, scope) {
        var card = scope && scope.closest ? scope.closest('.card') : null;
        var target = selector && ((card && card.querySelector(selector)) || document.querySelector(selector));
        if (!target) return false;
        target.click();
        return true;
    }

    function controlLabel(control) {
        return text(control && (control.getAttribute('aria-label') || control.getAttribute('title') || control.textContent)) || '操作';
    }

    function isBatchControl(control) {
        return /(?:批量|选中|batch|btn-app-del|file-batch|listed|delist|\bhandle\b)/i.test([control.className, controlLabel(control)].join(' '));
    }

    function isDangerControl(control) {
        return /(?:danger|删除|移除|清理|清空|卸载|解绑|驳回|拒绝|禁用|停用|停止|退出)/i.test([control.className, controlLabel(control)].join(' '));
    }

    function controlAvailable(control) {
        if (!control || control.disabled || control.hidden) return false;
        var boundary = control.closest('.card-header') || control.closest('.card') || document.body;
        var node = control;
        while (node && node !== boundary.parentElement) {
            if (
                node.hidden ||
                node.getAttribute('aria-disabled') === 'true' ||
                (node.getAttribute('aria-hidden') === 'true' && !node.classList.contains('dropdown-menu')) ||
                node.classList.contains('d-none') ||
                node.classList.contains('hide') ||
                node.classList.contains('hidden') ||
                node.classList.contains('disabled')
            ) return false;
            var inlineStyle = node.style;
            if (inlineStyle && (inlineStyle.visibility === 'hidden' || (inlineStyle.display === 'none' && !node.classList.contains('dropdown-menu')))) return false;
            node = node.parentElement;
        }
        if (window.getComputedStyle && window.getComputedStyle(control).display === 'none') return false;
        return true;
    }

    function discoveredControls(desktop, recipe, batch) {
        var card = desktop && desktop.closest ? desktop.closest('.card') : null;
        if (!card) return [];
        var configured = ['toolbar', 'batch'].reduce(function (selectors, group) {
            ((((recipe || {}).actions || {})[group]) || []).forEach(function (item) { if (item.selector) selectors.push(item.selector); });
            return selectors;
        }, []);
        var selector = ':scope > .card-header button, :scope > .card-header a[href], :scope > .card-header [role="button"], ' +
            ':scope > .card-header .dropdown-menu button, :scope > .card-header .dropdown-menu a[href], :scope > .card-header .dropdown-menu [role="button"]';
        return Array.from(card.querySelectorAll(selector)).filter(function (control, index, list) {
            if (list.indexOf(control) !== index || !controlAvailable(control)) return false;
            if (control.matches('[role="tab"]') || control.closest('[role="tablist"], .nav-tabs, .nav-pills')) return false;
            if (control.matches('[data-bs-toggle="dropdown"], [data-toggle="dropdown"], [data-kt-menu-trigger]')) return false;
            if (configured.some(function (selector) { try { return control.matches(selector); } catch (error) { return false; } })) return false;
            return isBatchControl(control) === batch;
        });
    }

    function openSort(snapshot) {
        var sortable = (snapshot.columns || []).filter(function (column) { return column && column.sort === true && column.field; });
        if (!sortable.length || !snapshot.__table) return false;
        var actions = [];
        sortable.forEach(function (column) {
            var label = text(column.title || column.field);
            actions.push({label: label + ' · 升序', icon: 'arrow_upward', run: function () { snapshot.__table.reload({pageNumber: 1, query: {sort_rule: 'asc', sort_field: column.field}}); }});
            actions.push({label: label + ' · 降序', icon: 'arrow_downward', run: function () { snapshot.__table.reload({pageNumber: 1, query: {sort_rule: 'desc', sort_field: column.field}}); }});
        });
        actions.push({label: '恢复默认排序', icon: 'restart_alt', run: function () { snapshot.__table.reload({pageNumber: 1, query: {sort_rule: '', sort_field: ''}}); }});
        return api.openActions({id: 'sort-' + snapshot.id, title: '排序方式', actions: actions});
    }

    function invokeAction(snapshot, action, row, index) {
        if (typeof action.invoke === 'function') return action.invoke(null, row, index);
        if (snapshot.__table && snapshot.__table.runAction) return snapshot.__table.runAction(action.id, row, null, index);
        return false;
    }

    function runWithConfirmation(options, run) {
        if (!options) return run();
        if (typeof options === 'string') options = {message: options};
        var prompt = text(options.message || '确认执行此操作吗？');
        var messageApi = typeof message !== 'undefined' ? message : window.message;
        if (messageApi && typeof messageApi.ask === 'function') {
            return messageApi.ask(prompt, run, text(options.title || '确认操作'), text(options.confirmText || '确认'));
        }
        if (window.confirm(prompt)) return run();
        return false;
    }

    function invokeRecipeAction(snapshot, recipe, action, row, index) {
        var configured = recipeAction(recipe, 'primary', action.id) || recipeAction(recipe, 'more', action.id);
        return runWithConfirmation(configured && configured.confirm, function () {
            return invokeAction(snapshot, action, row, index);
        });
    }

    function availableActions(snapshot, row) {
        return (snapshot.actions || []).filter(function (action) {
            try { return typeof action.show !== 'function' || action.show(row); } catch (error) { return false; }
        });
    }

    function copyConfiguredField(configured, row) {
        var value = rawValue(row, configured.copyField);
        value = value == null ? '' : String(value).trim();
        if (!value) return false;
        var messageApi = typeof message !== 'undefined' ? message : window.message;
        var subject = text(configured.label || '内容').replace(/^复制/, '') || '内容';
        var success = function () { if (messageApi && typeof messageApi.success === 'function') messageApi.success(subject + '已复制'); };
        var failure = function () { if (messageApi && typeof messageApi.error === 'function') messageApi.error(subject + '复制失败'); };
        var fallback = function () {
            var input = document.createElement('textarea');
            input.value = value;
            input.readOnly = true;
            input.setAttribute('aria-hidden', 'true');
            input.style.position = 'fixed';
            input.style.inset = '0 auto auto -9999px';
            input.style.opacity = '0';
            document.body.appendChild(input);
            input.focus();
            input.select();
            input.setSelectionRange(0, input.value.length);
            var copied = false;
            try { copied = document.execCommand('copy'); } catch (error) {}
            input.remove();
            if (copied) success(); else failure();
            return copied;
        };
        if (window.util && typeof window.util.copyTextToClipboard === 'function') {
            window.util.copyTextToClipboard(value, success, fallback);
            return true;
        }
        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            navigator.clipboard.writeText(value).then(success).catch(fallback);
            return true;
        }
        return fallback();
    }

    function configuredFieldActions(recipe, row) {
        var groups = (recipe && recipe.actions) || {};
        return ['primary', 'more'].reduce(function (actions, group) {
            (groups[group] || []).forEach(function (configured) {
                if (!configured || !configured.id || !configured.copyField || actions.some(function (action) { return action.id === configured.id; })) return;
                if (rawValue(row, configured.copyField) == null || rawValue(row, configured.copyField) === '') return;
                actions.push({
                    id: configured.id,
                    title: configured.label,
                    icon: configured.icon,
                    invoke: function () { return copyConfiguredField(configured, row); }
                });
            });
            return actions;
        }, []);
    }

    function recipeAction(recipe, group, id) {
        return (((recipe || {}).actions || {})[group] || []).find(function (item) { return item.id === id; });
    }

    function actionLabel(recipe, action) {
        var configured = recipeAction(recipe, 'primary', action.id) || recipeAction(recipe, 'more', action.id);
        if (configured && configured.label) return text(configured.label);
        if (action.title) return text(action.title);
        var signature = [action.icon, action.class, action.field, action.definition && action.definition.tips].filter(Boolean).join(' ');
        var inferred = [
            [/(?:trash|delete|remove)/i, '删除'],
            [/(?:pen-to-square|edit|pencil)/i, '编辑'],
            [/(?:gear|cog|setting)/i, '配置'],
            [/(?:copy|clone)/i, '复制'],
            [/(?:download|down-to-line)/i, '下载'],
            [/(?:upload|cloud-arrow-up|up-from-bracket)/i, '上传'],
            [/(?:unlock|lock-open)/i, '解锁'],
            [/(?:lock|lock-keyhole)/i, '锁定'],
            [/(?:eye|view|detail|circle-info)/i, '查看'],
            [/(?:plus|add|circle-plus)/i, '新增'],
            [/(?:sync|rotate|arrows)/i, '同步'],
            [/(?:link)/i, '连接']
        ].find(function (item) { return item[0].test(signature); });
        return inferred ? inferred[1] : '操作';
    }

    function actionDanger(recipe, action) {
        var configured = recipeAction(recipe, 'primary', action.id) || recipeAction(recipe, 'more', action.id);
        return action.danger === true || Boolean(configured && configured.danger === true);
    }

    function orderedActions(recipe, actions, group) {
        var configured = ((((recipe || {}).actions || {})[group]) || []).map(function (item) { return item.id; });
        return actions.slice().sort(function (left, right) {
            var leftIndex = configured.indexOf(left.id);
            var rightIndex = configured.indexOf(right.id);
            var leftRank = leftIndex >= 0 ? leftIndex : configured.length + (actionDanger(recipe, left) ? 200 : 100);
            var rightRank = rightIndex >= 0 ? rightIndex : configured.length + (actionDanger(recipe, right) ? 200 : 100);
            return leftRank - rightRank;
        });
    }

    function recipeShowsPrimaryActions(recipe) {
        return !recipe || !recipe.actions || recipe.actions.showPrimary !== false;
    }

    function columnAvailable(column, row) {
        if (!column || !column.field || ['switch', 'input', 'select'].indexOf(column.type) < 0) return false;
        try { return typeof column.show !== 'function' || column.show(row); } catch (error) { return false; }
    }

    function updateInlineValue(snapshot, row, column, value) {
        if (!snapshot.__table || typeof snapshot.__table.updateField !== 'function') return false;
        return snapshot.__table.updateField(row, column.field, value, {reload: column.reload === true});
    }

    function inlineSwitchEnabled(value) {
        return /^(?:1|ON|TRUE|YES)$/.test(String(value == null ? '' : value).trim().toUpperCase());
    }

    function inlineSwitchDisplayLabel(value) {
        var label = text(value);
        if (/^ON$/i.test(label)) return '已开启';
        if (/^OFF$/i.test(label)) return '已关闭';
        return label;
    }

    function inlineSwitchState(column, value) {
        var checked = inlineSwitchEnabled(value);
        var labels = String(column.text || '开启|关闭').split('|');
        var onLabel = inlineSwitchDisplayLabel(labels[0] || '开启');
        var offLabel = inlineSwitchDisplayLabel(labels[1] || '关闭');
        return {
            checked: checked,
            currentLabel: checked ? onLabel : offLabel,
            nextLabel: checked ? offLabel : onLabel,
            nextValue: checked ? 0 : 1
        };
    }

    function renderInlineSwitch(card, recipe, snapshot, columns, row, moreControl, recordTitle) {
        var definition = recipeDefinitions(recipe, 'inlineSwitch')[0];
        if (!definition || !moreControl) return false;

        card.classList.add('admin-mobile-data-card--inline-switch');
        var column = columns.find(function (candidate) {
            return String(candidate.field) === String(definition.field) && columnAvailable(candidate, row);
        });
        if (!column) {
            moreControl.remove();
            return true;
        }

        var control = document.createElement('label');
        control.className = 'admin-mobile-inline-switch';
        var input = document.createElement('input');
        input.type = 'checkbox';
        input.setAttribute('role', 'switch');
        input.setAttribute('aria-label', (text(definition.label || column.title || column.field) || '切换状态') + '：' + recordTitle);
        input.checked = inlineSwitchEnabled(rawValue(row, definition.field));
        input.setAttribute('aria-checked', input.checked ? 'true' : 'false');
        input.disabled = !snapshot.__table || typeof snapshot.__table.updateField !== 'function';

        var track = document.createElement('span');
        track.className = 'admin-mobile-inline-switch-track';
        track.setAttribute('aria-hidden', 'true');
        control.append(input, track);
        control.addEventListener('click', function (event) { event.stopPropagation(); });
        input.addEventListener('change', function (event) {
            event.stopPropagation();
            var next = input.checked ? 1 : 0;
            input.setAttribute('aria-checked', input.checked ? 'true' : 'false');
            if (updateInlineValue(snapshot, row, column, next) === false) {
                input.checked = !input.checked;
                input.setAttribute('aria-checked', input.checked ? 'true' : 'false');
            }
        });

        moreControl.replaceWith(control);
        return true;
    }

    function inlineInputEditor(snapshot, row, column) {
        var content = document.createElement('div');
        content.className = 'admin-mobile-filter-form admin-mobile-popup-form';
        var label = document.createElement('label');
        label.className = 'layui-form-label';
        label.textContent = text(column.title || column.field);
        var input = document.createElement('input');
        input.className = 'layui-input';
        input.type = 'text';
        var current = rawValue(row, column.field);
        input.value = current == null ? '' : String(current);
        var inputMode = String(column.inputmode || column.inputMode || '').toLowerCase();
        var enterKeyHint = String(column.enterkeyhint || column.enterKeyHint || 'done').toLowerCase();
        if (['none', 'text', 'decimal', 'numeric', 'tel', 'search', 'email', 'url'].indexOf(inputMode) >= 0) {
            input.inputMode = inputMode;
        } else if (/^-?\d+(?:\.\d+)?$/.test(input.value)) {
            input.inputMode = 'decimal';
        }
        if (['enter', 'done', 'go', 'next', 'previous', 'search', 'send'].indexOf(enterKeyHint) >= 0) {
            input.enterKeyHint = enterKeyHint;
        }
        input.autocomplete = 'off';
        var footer = document.createElement('div');
        footer.className = 'admin-mobile-filter-actions';
        footer.innerHTML = '<button type="button">取消</button><button type="button">保存修改</button>';
        content.append(label, input, footer);
        var sheet = api.openSheet({
            id: 'inline-' + snapshot.id + '-' + String(column.field).replace(/[^a-z0-9_-]+/gi, '-'),
            title: '修改' + text(column.title || column.field),
            subtitle: '保存后立即同步到当前记录',
            content: content,
            guardUnsaved: true
        });
        if (!sheet) return false;
        var save = function () {
            if (updateInlineValue(snapshot, row, column, input.value)) {
                if (typeof sheet.commit === 'function') sheet.commit();
                else sheet.close();
            }
        };
        footer.firstElementChild.addEventListener('click', function () { sheet.close(); });
        footer.lastElementChild.addEventListener('click', save);
        input.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter') return;
            event.preventDefault();
            save();
        });
        window.setTimeout(function () { if (input.isConnected) { input.focus(); input.select(); } }, 60);
        return true;
    }

    function selectOptions(snapshot, row, column) {
        var options = [];
        var root = snapshot.element && (snapshot.element.closest('.bootstrap-table') || snapshot.element.parentElement);
        if (root) {
            var controls = Array.from(root.querySelectorAll('select[data-field]'));
            var select = controls.find(function (control) {
                return control.getAttribute('data-field') === String(column.field) && String(control.getAttribute('data-id')) === String(row.id);
            });
            if (select) options = Array.from(select.options).map(function (option) { return {value: option.value, label: option.textContent}; });
        }
        if (!options.length && Array.isArray(column.dict)) {
            options = column.dict.map(function (option) {
                return {value: option.id == null ? option.value : option.id, label: text(option.name || option.label || option.value)};
            });
        }
        return options;
    }

    function inlineActions(snapshot, columns, row) {
        if (!snapshot.__table || typeof snapshot.__table.updateField !== 'function') return [];
        return columns.filter(function (column) { return columnAvailable(column, row); }).map(function (column) {
            var title = text(column.title || column.field);
            var current = rawValue(row, column.field);
            if (column.type === 'switch') {
                var switchState = inlineSwitchState(column, current);
                return {
                    kind: 'switch',
                    field: String(column.field || ''),
                    title: title,
                    label: title + ' · 设为' + switchState.nextLabel,
                    currentLabel: switchState.currentLabel,
                    nextLabel: switchState.nextLabel,
                    checked: switchState.checked,
                    icon: switchState.checked ? 'toggle_off' : 'toggle_on',
                    run: function (onUpdated) {
                        var latest = inlineSwitchState(column, rawValue(row, column.field));
                        var updated = updateInlineValue(snapshot, row, column, latest.nextValue);
                        if (updated && typeof onUpdated === 'function') {
                            onUpdated(inlineSwitchState(column, latest.nextValue));
                        }
                        return updated;
                    }
                };
            }
            if (column.type === 'select') {
                var options = selectOptions(snapshot, row, column);
                if (!options.length) return null;
                return {
                    label: '修改' + title,
                    icon: 'list_alt',
                    run: function () {
                        api.openActions({
                            id: 'inline-select-' + snapshot.id + '-' + String(column.field).replace(/[^a-z0-9_-]+/gi, '-'),
                            title: '选择' + title,
                            actions: options.map(function (option) {
                                return {
                                    label: option.label,
                                    icon: String(option.value) === String(current) ? 'check_circle' : 'radio_button_unchecked',
                                    run: function () { return updateInlineValue(snapshot, row, column, option.value); }
                                };
                            })
                        });
                    }
                };
            }
            return {label: '修改' + title, icon: 'edit', run: function () { return inlineInputEditor(snapshot, row, column); }};
        }).filter(Boolean);
    }

    function applyRecipePresentation(snapshot, recipe) {
        var presentation = snapshot;
        if (recipe && recipe.selection === false) {
            presentation = Object.assign({}, presentation, {
                selection: Object.assign({}, snapshot.selection || {}, {enabled: false, rows: [], ids: []})
            });
        }
        if (recipe && Array.isArray(recipe.stateOptions) && snapshot.state) {
            if (presentation === snapshot) presentation = Object.assign({}, snapshot);
            presentation.state = Object.assign({}, snapshot.state, {
                options: recipe.stateOptions.map(function (option) {
                    option = typeof option === 'object' ? option : {value: option, label: option};
                    var value = option.value == null ? (option.id == null ? '' : option.id) : option.value;
                    return {
                        value: value,
                        label: option.label || option.name || String(value),
                        active: String(value) === String(snapshot.state.value == null ? '' : snapshot.state.value)
                    };
                })
            });
        }
        return presentation;
    }

    function appendDetailDefinition(definitions, fields, item) {
        if (!item) return;
        item = typeof item === 'string' ? {field: item, label: item} : Object.assign({}, item);
        if (!item.field || item.field === 'detail_view') return;
        item.label = item.label || item.title || item.field;
        var key = String(item.field);
        var existing = fields[key];
        if (!existing) {
            fields[key] = item;
            definitions.push(item);
            return;
        }
        Object.keys(item).forEach(function (property) {
            if (existing[property] === undefined || existing[property] === null || existing[property] === '') existing[property] = item[property];
        });
    }

    function mergedDetailDefinitions(recipe, snapshot) {
        var definitions = [];
        var fields = Object.create(null);
        // Mobile-specific labels/order win, while Table detail metadata can add
        // formatters and dictionaries. Final hook-processed columns are the
        // fallback so plugin-injected fields never disappear from "more info".
        recipeDefinitions(recipe, 'details').forEach(function (item) { appendDetailDefinition(definitions, fields, item); });
        var detail = snapshot.detail || {};
        var columnFields = detail.column && Array.isArray(detail.column.fields) ? detail.column.fields : [];
        columnFields.forEach(function (item) { appendDetailDefinition(definitions, fields, item); });
        if (Array.isArray(detail.definition)) detail.definition.forEach(function (item) { appendDetailDefinition(definitions, fields, item); });
        if (Array.isArray(detail.button)) detail.button.forEach(function (item) { appendDetailDefinition(definitions, fields, item); });
        detailColumns(snapshot).forEach(function (column) {
            appendDetailDefinition(definitions, fields, Object.assign({}, column, {label: text(column.title || column.field), __mobileColumn: true}));
        });
        return definitions;
    }

    function appendCustomDetailContent(content, snapshot, row) {
        var detail = snapshot.detail || {};
        if (typeof detail.definition !== 'function' || typeof detail.render !== 'function') return;
        var rendered;
        try { rendered = detail.render(row); } catch (error) { return; }
        if (!rendered) return;
        var custom = document.createElement('div');
        custom.className = 'admin-mobile-detail-custom';
        if (typeof rendered === 'string') {
            var template = document.createElement('template');
            template.innerHTML = rendered;
            sanitizeTree(template.content);
            custom.appendChild(template.content);
        }
        else if (rendered.nodeType) custom.appendChild(rendered.cloneNode(true));
        else if (rendered.jquery && typeof rendered.each === 'function') rendered.each(function () { custom.appendChild(this.cloneNode(true)); });
        sanitizeTree(custom);
        if (custom.childNodes.length) content.appendChild(custom);
    }

    function detailContent(recipe, snapshot, columns, row, index, headingField, options) {
        options = options || {};
        var content = document.createElement('div');
        content.className = 'admin-mobile-detail-content';
        var list = document.createElement('dl');
        list.className = 'admin-mobile-detail-list';
        if (options.record === true) {
            content.classList.add('admin-mobile-record-details');
            list.classList.add('admin-mobile-record-detail-list');
        }
        var definitions = Array.isArray(options.definitions)
            ? options.definitions.slice()
            : mergedDetailDefinitions(recipe, snapshot);
        var excludedFields = new Set();
        var preservedFields = new Set((options.preserveFields || []).map(String));
        var primary = recipeDefinitions(recipe, 'primary')[0];
        if (primary && primary.field) excludedFields.add(String(primary.field));
        if (headingField) excludedFields.add(String(headingField));
        mediaFields(mediaDefinition(recipe, row)).forEach(function (field) { excludedFields.add(String(field)); });
        (options.excludeFields || []).forEach(function (field) {
            if (field !== undefined && field !== null && field !== '') excludedFields.add(String(field));
        });
        definitions.forEach(function (definition) {
            if (excludedFields.has(String(definition.field)) && !preservedFields.has(String(definition.field))) return;
            if (typeof definition.show === 'function') {
                try { if (!definition.show(row)) return; } catch (error) {}
            }
            var dt = document.createElement('dt'); dt.textContent = text(definition.label || definition.field);
            var dd = document.createElement('dd');
            var updateValue = function () {
                var nextValue = definitionValue(snapshot, definition, columns, row, index, recipe);
                if (dd.textContent !== nextValue) dd.textContent = nextValue;
            };
            updateValue();
            if (relativeTimeDefinition(recipe, definition, snapshot && snapshot.__adminMobilePageRecipe) && relativeTimeText(dd.textContent)) {
                dd.setAttribute('data-admin-mobile-live-text', '');
                dd.__adminMobileLiveTextUpdate = updateValue;
                ensureLiveTextTimer();
            }
            if (options.record === true) {
                var item = document.createElement('div');
                item.className = 'admin-mobile-record-detail-item';
                item.append(dt, dd);
                list.appendChild(item);
            } else {
                list.append(dt, dd);
            }
        });
        if (list.children.length) content.appendChild(list);
        if (options.includeCustom !== false) appendCustomDetailContent(content, snapshot, row);
        return content;
    }

    function openDetails(recipe, snapshot, columns, row, index, heading, headingField) {
        var title = heading || '详细信息';
        var columnTitle = snapshot.detail && snapshot.detail.column && snapshot.detail.column.title;
        if (columnTitle) {
            try { title = typeof columnTitle === 'function' ? columnTitle(row) : columnTitle; } catch (error) {}
        }
        var definitions = mergedDetailDefinitions(recipe, snapshot);
        // detail.open is the desktop column popup adapter. Calling it here used
        // to return true and short-circuit the complete mobile detail sheet.
        return api.openSheet({id: 'row-detail-' + snapshot.id, title: text(title) || heading || '详细信息', subtitle: '完整记录', content: detailContent(recipe, snapshot, detailColumns(snapshot), row, index, headingField), fullScreen: definitions.length > 10});
    }

    function recordSheetEnabled(recipe, pageRecipe) {
        var candidates = [recipe, pageRecipe].filter(Boolean);
        if (candidates.some(function (candidate) { return candidate.recordSheet === true; })) return true;
        return !candidates.some(function (candidate) {
            if (candidate.recordSheet === false || candidate.cardCta || candidate.staticCard || candidate.inlineSwitch) return true;
            var id = String(candidate.id || '').toLowerCase();
            if ([
                'admin-plugin',
                'admin-pay-plugin',
                'admin-store-home',
                'admin-store-developer',
                'admin-license-transfer',
                'admin-category-group-visibility',
                'admin-commodity-group-pricing',
                'admin-group-discount-config',
                'admin-commodity-group-choice',
                'admin-photo-album-picker'
            ].indexOf(id) >= 0) return true;
            if (/(?:^|-)plugin(?:-|$)/.test(id)) return true;
            return /^(?:supply-market|third-dock)(?:-|$)/.test(id);
        });
    }

    function recordHeaderContent(recipe, snapshot, columns, row, index, title, subtitle) {
        var header = document.createElement('div');
        header.className = 'admin-mobile-record-head';

        var media = document.createElement('span');
        var descriptor = mediaDefinition(recipe, row);
        var shape = descriptor && descriptor.shape === 'circle' ? 'circle' : 'rounded';
        media.className = 'admin-mobile-record-head__media admin-mobile-record-head__media--' + shape;
        var fallbackIcon = document.createElement('span');
        fallbackIcon.className = 'material-icons-outlined';
        fallbackIcon.setAttribute('aria-hidden', 'true');
        fallbackIcon.textContent = descriptor && descriptor.fallbackIcon || mediaIcon(recipe);
        media.appendChild(fallbackIcon);
        var source = mediaDescriptorSource(descriptor, row, snapshot, columns, index);
        if (source) {
            var image = document.createElement('img');
            image.alt = '';
            image.loading = 'lazy';
            image.addEventListener('load', function () { media.classList.add('has-image'); }, {once: true});
            image.addEventListener('error', function () { image.remove(); }, {once: true});
            image.src = source;
            media.appendChild(image);
        }

        var copy = document.createElement('span');
        copy.className = 'admin-mobile-record-head__copy';
        var heading = document.createElement('strong');
        heading.className = 'admin-mobile-record-head__title';
        heading.setAttribute('data-admin-mobile-overlay-title', '');
        heading.textContent = title || '记录详情';
        copy.appendChild(heading);
        if (subtitle && subtitle !== title) {
            var description = document.createElement('small');
            description.className = 'admin-mobile-record-head__subtitle';
            description.textContent = subtitle;
            copy.appendChild(description);
        }
        header.append(media, copy);
        return header;
    }

    function recordSummaryDefinitions(recipe) {
        var configured = recipeDefinitions(recipe, 'summary');
        if (configured.length) return configured.slice(0, 3);
        var fields = new Set();
        return recipeDefinitions(recipe, 'status').concat(recipeDefinitions(recipe, 'metrics')).filter(function (definition) {
            var key = String(definition.field || '');
            if (!key || fields.has(key)) return false;
            fields.add(key);
            return true;
        }).slice(0, 3);
    }

    function recordSummaryContent(recipe, snapshot, columns, row, index, definitions) {
        var list = document.createElement('dl');
        list.className = 'admin-mobile-record-summary';
        var configured = recipeDefinitions(recipe, 'summary').length > 0;
        definitions.forEach(function (definition) {
            if (typeof definition.show === 'function') {
                try { if (!definition.show(row)) return; } catch (error) {}
            }
            var value = definitionValue(snapshot, definition, columns, row, index, recipe);
            if (!configured && (!value || value === '-')) return;
            var item = document.createElement('div');
            item.className = 'admin-mobile-record-summary__item';
            item.setAttribute('data-admin-mobile-tone', definitionTone(definition, value, row, index, snapshot, statusTone));
            var label = document.createElement('dt');
            label.textContent = text(definition.label || definition.field);
            var output = document.createElement('dd');
            output.textContent = value || '-';
            if (relativeTimeDefinition(recipe, definition, snapshot && snapshot.__adminMobilePageRecipe) && relativeTimeText(output.textContent)) {
                bindLiveTextControl(output, function () {
                    return definitionValue(snapshot, definition, columns, row, index, recipe);
                });
            }
            item.append(label, output);
            list.appendChild(item);
        });
        list.setAttribute('data-admin-mobile-count', String(list.children.length));
        return list;
    }

    function recordActionButton(recipe, action, run, options) {
        options = options || {};
        var label = text(options.label || actionLabel(recipe, action)) || '操作';
        var danger = options.danger === true || actionDanger(recipe, action);
        var warning = !danger && /锁定|停用|禁用|驳回|冻结|下架|取消|清空|作废/.test(label);
        var configured = recipeAction(recipe, 'primary', action.id) || recipeAction(recipe, 'more', action.id);
        var configuredIcon = configured && /^[a-z0-9_]+$/i.test(String(configured.icon || ''))
            ? configured.icon
            : '';
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'admin-mobile-record-action' +
            (danger ? ' admin-mobile-record-action--danger is-danger' : '') +
            (warning ? ' admin-mobile-record-action--warning' : '');
        var icon = document.createElement('span');
        icon.className = 'material-icons-outlined admin-mobile-record-action__icon';
        icon.setAttribute('aria-hidden', 'true');
        icon.textContent = text(options.icon || configuredIcon || cardActionIcon(label, danger));
        var caption = document.createElement('span');
        caption.className = 'admin-mobile-record-action__text';
        var captionTitle = document.createElement('strong');
        captionTitle.textContent = label;
        caption.appendChild(captionTitle);
        button.append(icon, caption);
        button.addEventListener('click', function () {
            api.dismissAllThen(run);
        });
        return button;
    }

    function recordSwitchButton(action) {
        var title = text(action.title || action.label) || '开关';
        var button = document.createElement('button');
        button.type = 'button';
        button.setAttribute('role', 'switch');
        if (action.field) button.setAttribute('data-admin-mobile-switch-field', action.field);

        var copy = document.createElement('span');
        copy.className = 'admin-mobile-record-switch__copy';
        var heading = document.createElement('strong');
        heading.textContent = title;
        copy.appendChild(heading);

        var track = document.createElement('span');
        track.className = 'admin-mobile-record-switch__track';
        track.setAttribute('aria-hidden', 'true');
        var thumb = document.createElement('span');
        thumb.className = 'admin-mobile-record-switch__thumb';
        track.appendChild(thumb);
        button.append(copy, track);
        var applyState = function (nextState) {
            var checked = nextState.checked === true;
            var currentLabel = text(nextState.currentLabel || (checked ? '已开启' : '已关闭'));
            var nextLabel = text(nextState.nextLabel || (checked ? '关闭' : '开启'));
            button.className = 'admin-mobile-record-switch ' + (checked ? 'is-on' : 'is-off');
            button.setAttribute('aria-checked', checked ? 'true' : 'false');
            button.setAttribute('aria-label', title + '，当前' + currentLabel + '，点击设为' + nextLabel);
        };
        applyState(action);
        button.addEventListener('click', function () {
            action.run(applyState);
        });
        return button;
    }

    function recordBadgeValues(row, definition) {
        var source = rawValue(row, definition.field);
        var values;
        if (Array.isArray(source)) values = source;
        else if (source && typeof source === 'object') values = Object.keys(source).map(function (key) { return source[key]; });
        else values = source == null ? [] : [source];

        var seen = Object.create(null);
        return values.map(function (value) {
            if (value && typeof value === 'object') value = value.label || value.name || value.title || value.value || '';
            return text(value);
        }).filter(function (value) {
            if (!value || seen[value]) return false;
            seen[value] = true;
            return true;
        });
    }

    function appendRecordBadgeSections(content, recipe, snapshot, row, index) {
        recipeDefinitions(recipe, 'recordBadges').forEach(function (definition) {
            var section = document.createElement('section');
            section.className = 'admin-mobile-record-section admin-mobile-record-section--badges';
            var sectionTitle = document.createElement('strong');
            sectionTitle.className = 'admin-mobile-record-section__title';
            sectionTitle.textContent = text(definition.title || definition.label || definition.field) || '相关信息';

            var list = document.createElement('div');
            list.className = 'admin-mobile-record-badges';
            list.setAttribute('role', 'list');
            var values = recordBadgeValues(row, definition);
            values.forEach(function (value) {
                var tone = definitionTone(definition, value, row, index, snapshot, statusTone);
                var badge = document.createElement('span');
                badge.className = 'admin-mobile-status-chip admin-mobile-status-chip--' + tone;
                badge.setAttribute('role', 'listitem');
                badge.setAttribute('data-admin-mobile-tone', tone);
                badge.setAttribute('data-admin-mobile-field', String(definition.field || ''));
                var label = document.createElement('span');
                label.textContent = value;
                badge.appendChild(label);
                list.appendChild(badge);
            });
            if (!values.length) {
                list.classList.add('is-empty');
                var empty = document.createElement('p');
                empty.className = 'admin-mobile-record-badges__empty';
                empty.textContent = text(definition.emptyText) || '暂无相关信息';
                list.appendChild(empty);
            }
            section.append(sectionTitle, list);
            content.appendChild(section);
        });
    }

    function openRecordSheet(recipe, snapshot, columns, row, index, title, headingField, subtitle, rowActions) {
        var recordColumns = detailColumns(snapshot);
        var recordSubtitleDefinition = recipeDefinitions(recipe, 'recordSubtitle')[0];
        var recordSubtitle = recordSubtitleDefinition
            ? definitionValue(snapshot, recordSubtitleDefinition, recordColumns, row, index, recipe)
            : '';
        if (!recordSubtitle || recordSubtitle === '-') recordSubtitle = subtitle || '';

        var content = document.createElement('div');
        content.className = 'admin-mobile-record-sheet';
        var summaryDefinitions = recordSummaryDefinitions(recipe);
        var summary = recordSummaryContent(recipe, snapshot, recordColumns, row, index, summaryDefinitions);
        if (summary.children.length) {
            var summarySection = document.createElement('section');
            summarySection.className = 'admin-mobile-record-section admin-mobile-record-section--summary';
            var summaryTitle = document.createElement('strong');
            summaryTitle.className = 'admin-mobile-record-section__title';
            summaryTitle.textContent = '记录摘要';
            summarySection.append(summaryTitle, summary);
            content.appendChild(summarySection);
        }

        appendRecordBadgeSections(content, recipe, snapshot, row, index);

        if (!recipe || recipe.recordDetails !== false) {
            var details = detailContent(recipe, snapshot, recordColumns, row, index, headingField, {
                record: true,
                excludeFields: summaryDefinitions.map(function (definition) { return definition.field; })
                    .concat(Array.isArray(recipe && recipe.recordDetailExclude) ? recipe.recordDetailExclude : []),
                preserveFields: ['__card_secret']
            });
            if (details.childNodes.length) {
                var detailSection = document.createElement('section');
                detailSection.className = 'admin-mobile-record-section admin-mobile-record-section--details';
                var detailTitle = document.createElement('strong');
                detailTitle.className = 'admin-mobile-record-section__title';
                detailTitle.textContent = '详细信息';
                detailSection.append(detailTitle, details);
                content.appendChild(detailSection);
            }
        }

        var inline = inlineActions(snapshot, columns, row);
        var switchActions = inline.filter(function (action) { return action.kind === 'switch'; });
        if (switchActions.length) {
            var switchSection = document.createElement('section');
            switchSection.className = 'admin-mobile-record-section admin-mobile-record-section--switches';
            var switchTitle = document.createElement('strong');
            switchTitle.className = 'admin-mobile-record-section__title';
            switchTitle.textContent = '开关';
            var switchList = document.createElement('div');
            switchList.className = 'admin-mobile-record-switches';
            switchActions.forEach(function (action) {
                switchList.appendChild(recordSwitchButton(action));
            });
            switchSection.append(switchTitle, switchList);
            content.appendChild(switchSection);
        }

        var actionList = document.createElement('div');
        actionList.className = 'admin-mobile-record-actions';
        inline.filter(function (action) { return action.kind !== 'switch'; }).forEach(function (action) {
            actionList.appendChild(recordActionButton(recipe, action, action.run, {
                label: action.label,
                icon: action.icon,
                danger: action.danger === true
            }));
        });
        orderedCardActions(recipe, rowActions).forEach(function (action) {
            actionList.appendChild(recordActionButton(recipe, action, function () {
                return invokeRecipeAction(snapshot, recipe, action, row, index);
            }));
        });
        if (actionList.children.length) {
            var actionSection = document.createElement('section');
            actionSection.className = 'admin-mobile-record-section admin-mobile-record-section--actions';
            var actionTitle = document.createElement('strong');
            actionTitle.className = 'admin-mobile-record-section__title';
            actionTitle.textContent = '可用操作';
            actionSection.append(actionTitle, actionList);
            content.appendChild(actionSection);
        }

        return api.openSheet({
            id: 'row-record-' + snapshot.id + '-' + index,
            title: title || '记录详情',
            headerContent: recordHeaderContent(recipe, snapshot, recordColumns, row, index, title || '记录详情', recordSubtitle),
            content: content,
            fullScreen: false,
            className: 'admin-mobile-overlay--record'
        });
    }

    function cardActionIcon(label, danger) {
        label = String(label || '');
        if (danger && /卸载|删除/.test(label)) return 'delete_forever';
        if (/复制/.test(label)) return 'content_copy';
        if (/解锁/.test(label)) return 'lock_open';
        if (/锁定|冻结/.test(label)) return 'lock';
        if (/编辑|修改|配置|设置/.test(label)) return 'edit';
        if (/查看|详细/.test(label)) return 'visibility';
        if (/余额/.test(label)) return 'account_balance_wallet';
        if (/硬币|元气/.test(label)) return 'monetization_on';
        if (/启用|开启|上架/.test(label)) return 'toggle_on';
        if (/禁用|停用|下架/.test(label)) return 'block';
        if (/发货|补单/.test(label)) return 'local_shipping';
        if (/同步|刷新/.test(label)) return 'sync';
        if (/新增|添加|导入/.test(label)) return 'add';
        if (/上传/.test(label)) return 'cloud_upload';
        if (/安装|获取/.test(label)) return 'download';
        if (/更新|升级/.test(label)) return 'system_update_alt';
        if (/购买|支付/.test(label)) return 'shopping_bag';
        if (/解绑|转移/.test(label)) return 'move_up';
        if (/官网|访问/.test(label)) return 'open_in_new';
        return danger ? 'warning' : 'arrow_forward';
    }

    function orderedCardActions(recipe, actions) {
        var configured = ['primary', 'more'].reduce(function (items, group) {
            return items.concat(((((recipe || {}).actions || {})[group]) || []).map(function (item) { return item.id; }));
        }, []);
        return actions.slice().sort(function (left, right) {
            var leftIndex = configured.indexOf(left.id);
            var rightIndex = configured.indexOf(right.id);
            if (leftIndex < 0) leftIndex = configured.length + (actionDanger(recipe, left) ? 100 : 0);
            if (rightIndex < 0) rightIndex = configured.length + (actionDanger(recipe, right) ? 100 : 0);
            return leftIndex - rightIndex;
        });
    }

    function cardOfferContent(recipe, snapshot, row, index) {
        var cardCta = recipe && recipe.cardCta || {};
        var offer = cardCta.offer;
        if (!offer || !offer.field) return null;
        var value = rawValue(row, offer.field);
        if (typeof offer.format === 'function') {
            try { value = offer.format(value, row, index, snapshot); } catch (error) {}
        }

        var section = document.createElement('section');
        section.className = 'admin-mobile-app-detail__commerce';
        var priceWrap = document.createElement('div');
        priceWrap.className = 'admin-mobile-app-detail__price-wrap';
        var label = document.createElement('small');
        label.className = 'admin-mobile-app-detail__price-label';
        label.textContent = text(offer.label || '应用价格');
        var price = document.createElement('strong');
        price.className = 'admin-mobile-app-detail__price';
        price.textContent = text(value == null || value === '' ? '-' : value) || '-';
        price.classList.toggle('is-free', price.textContent === '免费');
        priceWrap.append(label, price);
        section.appendChild(priceWrap);

        var benefits = document.createElement('div');
        benefits.className = 'admin-mobile-app-detail__benefits';
        (Array.isArray(offer.benefits) ? offer.benefits : []).forEach(function (benefit) {
            if (!benefit) return;
            if (typeof benefit.show === 'function') {
                try { if (!benefit.show(row, index, snapshot)) return; } catch (error) { return; }
            }
            var badge = document.createElement('span');
            var tone = String(benefit.tone || 'success').trim().toLowerCase();
            badge.className = 'admin-mobile-app-detail__benefit is-' + (/^(?:success|neutral)$/.test(tone) ? tone : 'success');
            badge.textContent = text(benefit.label || '专属权益');
            benefits.appendChild(badge);
        });
        if (benefits.children.length) section.appendChild(benefits);
        else section.classList.add('is-price-only');
        return section;
    }

    function cardCtaContent(recipe, snapshot, columns, row, index, heading, headingField, subtitle, rowActions) {
        var content = document.createElement('div');
        content.className = 'admin-mobile-app-detail';
        var hero = document.createElement('section');
        hero.className = 'admin-mobile-app-detail__hero';
        hero.innerHTML = '<span class="admin-mobile-app-detail__icon"><span class="material-icons-outlined" aria-hidden="true"></span></span><div><strong></strong><small></small></div>';
        setDefinitionInlineContent(hero.querySelector('strong'), recipeDefinitions(recipe, 'primary')[0], row, heading || '-');
        hero.querySelector('small').textContent = subtitle && subtitle !== heading ? subtitle : '';
        var heroIcon = hero.querySelector('.admin-mobile-app-detail__icon');
        var mediaDescriptor = mediaDefinition(recipe, row);
        heroIcon.querySelector('.material-icons-outlined').textContent = mediaDescriptor && mediaDescriptor.fallbackIcon || mediaIcon(recipe);
        var source = mediaDescriptorSource(mediaDescriptor, row, snapshot, columns, index);
        if (source) {
            var image = document.createElement('img');
            image.alt = '';
            image.src = source;
            image.addEventListener('load', function () { heroIcon.classList.add('has-image'); }, {once: true});
            image.addEventListener('error', function () { image.remove(); }, {once: true});
            heroIcon.appendChild(image);
        }
        content.appendChild(hero);

        var offer = cardOfferContent(recipe, snapshot, row, index);
        if (offer) content.appendChild(offer);

        var details = detailContent(recipe, snapshot, detailColumns(snapshot), row, index, headingField, {
            definitions: recipeDefinitions(recipe, 'details'),
            includeCustom: false
        });
        details.classList.add('admin-mobile-app-detail__details');
        if (details.childNodes.length) content.appendChild(details);

        var actionSection = document.createElement('section');
        actionSection.className = 'admin-mobile-app-detail__action-section';
        var actionTitle = document.createElement('strong');
        actionTitle.className = 'admin-mobile-app-detail__action-title';
        actionTitle.textContent = '可用操作';
        actionSection.appendChild(actionTitle);
        var actionList = document.createElement('div');
        actionList.className = 'admin-mobile-app-detail__actions';
        orderedCardActions(recipe, rowActions).forEach(function (action) {
            var label = actionLabel(recipe, action);
            var danger = actionDanger(recipe, action);
            var primary = !danger && Boolean(recipeAction(recipe, 'primary', action.id) || /安装|更新|购买/.test(label));
            var button = document.createElement('button');
            button.type = 'button';
            button.className = danger ? 'is-danger' : (primary ? 'is-primary' : 'is-secondary');
            var icon = document.createElement('span');
            icon.className = 'material-icons-outlined admin-mobile-app-detail__action-icon';
            icon.setAttribute('aria-hidden', 'true');
            icon.textContent = cardActionIcon(label, danger);
            var caption = document.createElement('span');
            caption.className = 'admin-mobile-app-detail__action-label';
            caption.textContent = label;
            button.append(icon, caption);
            button.addEventListener('click', function () {
                api.dismissAllThen(function () { invokeRecipeAction(snapshot, recipe, action, row, index); });
            });
            actionList.appendChild(button);
        });
        if (!actionList.children.length) {
            var empty = document.createElement('p');
            empty.className = 'admin-mobile-app-detail__action-empty';
            empty.textContent = '当前应用暂无可执行操作';
            actionList.appendChild(empty);
        }
        actionSection.appendChild(actionList);
        content.appendChild(actionSection);
        return content;
    }

    function openCardCta(recipe, snapshot, columns, row, index, heading, headingField, subtitle, rowActions) {
        var cardCta = recipe && recipe.cardCta || {};
        var sheetSubtitle = Object.prototype.hasOwnProperty.call(cardCta, 'subtitle')
            ? text(cardCta.subtitle)
            : '完整信息';
        return api.openSheet({
            id: 'row-cta-' + snapshot.id + '-' + index,
            title: text(cardCta.title || '应用详情'),
            subtitle: sheetSubtitle,
            content: cardCtaContent(recipe, snapshot, columns, row, index, heading, headingField, subtitle, rowActions),
            className: 'admin-mobile-overlay--app-market'
        });
    }

    function cardSearchText(recipe, snapshot, columns, entry, primary, primaryDefinitions, statusDefinitions, metricDefinitions, treeNode) {
        var row = entry.row;
        var index = entry.index;
        var heading = primaryDefinitions.length
            ? primaryDefinitionValue(snapshot, primaryDefinitions[0], columns, row, index, recipe)
            : (primary ? definitionValue(snapshot, mobileColumnDefinition(primary), columns, row, index, recipe) : ('记录 ' + (index + 1)));
        var values = [heading, cardSubtitle(recipe, snapshot, columns, row, index, primaryDefinitions, heading)];
        statusDefinitions.slice(0, 3).forEach(function (definition) {
            values.push(definitionValue(snapshot, definition, columns, row, index, recipe));
        });
        metricDefinitions.slice(0, recipeMetricLimit(recipe)).forEach(function (definition) {
            values.push(definition.label || definition.field);
            values.push(definitionValue(snapshot, definition, columns, row, index, recipe));
        });
        var rowActions = availableActions(snapshot, row);
        var primaryIds = ((((recipe || {}).actions || {}).primary) || []).map(function (item) { return item.id; });
        if (recipeShowsPrimaryActions(recipe)) {
            orderedActions(recipe, rowActions.filter(function (action) {
                return primaryIds.indexOf(action.id) >= 0 || (!primaryIds.length && action.category === 'primary');
            }), 'primary').slice(0, 2).forEach(function (action) {
                values.push(actionLabel(recipe, action));
            });
        }
        if (treeNode) {
            values.push(treeLevelLabel(treeNode.depth));
            values.push(treeNode.parent && treeNode.parent.label ? '上级：' + treeNode.parent.label : (treeNode.depth ? '隶属于上一级' : '顶级分类'));
        }
        return values.map(text).filter(Boolean).join(' ').toLocaleLowerCase();
    }

    function currentSearchValues(search) {
        return search && typeof search.getValue === 'function' ? search.getValue() : (search && typeof search.value === 'function' ? search.value() : {});
    }

    function captureSearchValues(search) {
        var values = Object.assign({}, currentSearchValues(search) || {});
        var definitions = search && typeof search.definitions === 'function' ? search.definitions() : [];
        definitions.forEach(function (definition) {
            if (!definition || definition.type !== 'remoteSelect') return;
            var control = search.controls && search.controls[definition.name];
            if (!control || typeof control.getValue !== 'function') return;
            try {
                values[definition.name] = control.getValue().map(function (item) {
                    return item && typeof item === 'object' ? Object.assign({}, item) : item;
                });
            } catch (error) {}
        });
        return values;
    }

    function restoreSearchValues(search, values) {
        if (!search || search.isDestroyed || !values) return;
        if (typeof search.setValue === 'function') {
            search.setValue(values, false);
            return;
        }
        if (!search.$instance) return;
        Object.keys(values).forEach(function (name) {
            search.$instance.find('[name]').filter(function () { return this.name === name; }).val(values[name]);
        });
        if (typeof layui !== 'undefined' && layui.form) layui.form.render();
    }

    function activeFilterCount(search) {
        var values = currentSearchValues(search) || {};
        return Object.keys(values).filter(function (key) {
            var value = values[key];
            return Array.isArray(value) ? value.length > 0 : value !== '' && value !== null && value !== undefined;
        }).length;
    }

    function snapshotFilterCount(snapshot) {
        var search = snapshot && snapshot.search && snapshot.search.instance;
        var state = snapshot && snapshot.state;
        var values = search ? (currentSearchValues(search) || {}) : {};
        var count = search ? activeFilterCount(search) : 0;
        var stateActive = state && state.value !== '' && state.value !== null && state.value !== undefined;
        if (!stateActive) return count;
        var stateKey = 'equal-' + state.field;
        var duplicate = values[stateKey] !== '' && values[stateKey] !== null && values[stateKey] !== undefined && String(values[stateKey]) === String(state.value);
        return count + (duplicate ? 0 : 1);
    }

    function visiblePopup(node) {
        if (!node || node.hidden || node.getAttribute('aria-hidden') === 'true' || !node.getClientRects().length) return false;
        var style = window.getComputedStyle ? window.getComputedStyle(node) : null;
        return !style || (style.display !== 'none' && style.visibility !== 'hidden');
    }

    function filterPickerOpen() {
        var selectors = [
            '.select2-container--open',
            '.layui-form-select.layui-form-selected',
            '.layui-dropdown.layui-show',
            '.layui-laydate:not(.layui-laydate-static)',
            'xm-select.xm-select-show',
            'xm-select[aria-expanded="true"]',
            'xm-select .xm-body:not(.relative)',
            '[role="combobox"][aria-expanded="true"]',
            '[aria-haspopup="listbox"][aria-expanded="true"]'
        ];
        return Array.from(document.querySelectorAll(selectors.join(','))).some(visiblePopup);
    }

    function shouldApplyFiltersOnEnter(event) {
        if (event.key !== 'Enter' || event.defaultPrevented || event.isComposing || event.keyCode === 229) return false;
        var target = event.target;
        if (!target || target.nodeType !== 1 || target.tagName !== 'INPUT' || target.disabled || target.readOnly) return false;
        var type = String(target.type || 'text').toLowerCase();
        if (type !== 'text' && type !== 'search') return false;
        if (target.matches('[list], [role="combobox"], [aria-autocomplete], [aria-haspopup="listbox"], .select2-search__field, .xm-search-input')) return false;
        if (target.closest('.select2-container, .select2-dropdown, .select2-search, .layui-form-select, .layui-select-title, .layui-dropdown, .layui-laydate, xm-select, [role="combobox"]')) return false;
        return !filterPickerOpen();
    }

    function openFilters(snapshot) {
        var search = snapshot.search && snapshot.search.instance;
        var state = snapshot.state;
        if (!search && !(state && state.options && state.options.length)) return false;
        var pageRecipe = snapshot && snapshot.__adminMobilePageRecipe;
        var storeFilter = pageRecipe && pageRecipe.id === 'admin-store-home';
        var originalSearchValues = captureSearchValues(search);
        var committed = false;
        var content = document.createElement('div');
        content.className = 'admin-mobile-filter-form';
        var originalForm = search && search.$instance && search.$instance[0];
        var marker = null;
        var duplicateStateField = null;
        if (originalForm && originalForm.parentNode) {
            marker = document.createComment('admin-mobile-search-position');
            originalForm.parentNode.insertBefore(marker, originalForm);
            originalForm.classList.add('admin-mobile-native-search');
            content.appendChild(originalForm);
            if (state && state.field) {
                var duplicateControl = originalForm.querySelector('[name="equal-' + state.field + '"]');
                duplicateStateField = duplicateControl && duplicateControl.closest('.layui-input-inline, .form-group, .form-field, label');
                if (duplicateStateField) duplicateStateField.setAttribute('data-admin-mobile-state-duplicate', '');
            }
        }
        if (state && state.options && state.options.length) {
            var stateGroup = document.createElement('fieldset');
            stateGroup.className = 'admin-mobile-state-filter';
            stateGroup.innerHTML = '<legend>' + (storeFilter ? '应用分类' : '状态') + '</legend><div></div>';
            state.options.forEach(function (option) {
                var label = document.createElement('label');
                label.innerHTML = '<input type="radio" name="admin-mobile-state" value=""><span></span>';
                label.querySelector('input').value = option.value == null ? '' : option.value;
                label.querySelector('input').checked = option.active === true;
                label.querySelector('span').textContent = text(option.label || '全部');
                stateGroup.querySelector('div').appendChild(label);
            });
            content.appendChild(stateGroup);
        }
        var actions = document.createElement('div');
        actions.className = 'admin-mobile-filter-actions';
        actions.innerHTML = '<button type="button" data-admin-mobile-filter-reset>重置</button><button type="button" data-admin-mobile-filter-submit>应用筛选</button>';
        content.appendChild(actions);
        var restore = function () {
            if (duplicateStateField) duplicateStateField.removeAttribute('data-admin-mobile-state-duplicate');
            if (!originalForm) return;
            originalForm.classList.remove('admin-mobile-native-search');
            if (marker && marker.parentNode) marker.parentNode.replaceChild(originalForm, marker);
        };
        var sheet = api.openSheet({
            id: 'filters-' + snapshot.id,
            title: storeFilter ? '搜索应用' : '搜索与筛选',
            subtitle: storeFilter ? '按名称搜索或选择应用分类' : '筛选条件会保留到本页刷新',
            content: content,
            fullScreen: ((snapshot.search && snapshot.search.definitions) || []).length > 6,
            onClose: function () {
                restore();
                if (!committed) restoreSearchValues(search, originalSearchValues);
            }
        });
        if (!sheet) { restore(); return false; }
        var apply = function () {
            var selectedState = content.querySelector('input[name="admin-mobile-state"]:checked');
            var nextState = selectedState ? selectedState.value : null;
            committed = true;
            if (state && typeof state.select === 'function' && nextState !== null && String(nextState) !== String(state.value == null ? '' : state.value)) state.select(nextState);
            else if (search && typeof search.submit === 'function') search.submit();
            sheet.close();
        };
        content.querySelector('[data-admin-mobile-filter-submit]').addEventListener('click', apply);
        content.addEventListener('keydown', function (event) {
            if (!shouldApplyFiltersOnEnter(event)) return;
            event.preventDefault();
            event.stopPropagation();
            apply();
        }, true);
        content.querySelector('[data-admin-mobile-filter-reset]').addEventListener('click', function () {
            committed = true;
            if (search && typeof search.reset === 'function') search.reset(false);
            if (state && typeof state.select === 'function' && String(state.value == null ? '' : state.value) !== '') state.select('');
            else if (search && typeof search.submit === 'function') search.submit();
            sheet.close();
        });
        return true;
    }

    function openLocalFilter(snapshot, recipe) {
        if (!localSearchAvailable(snapshot, recipe)) return false;
        var content = document.createElement('div');
        content.className = 'admin-mobile-filter-form';
        var field = document.createElement('label');
        field.className = 'admin-mobile-native-search admin-mobile-local-search';
        field.innerHTML = '<span>关键词</span><input type="search" class="layui-input" inputmode="search" autocomplete="off" enterkeyhint="search">';
        var input = field.querySelector('input');
        input.placeholder = '搜索当前已加载的记录';
        input.value = localQueries.get(snapshot.id) || '';
        var actions = document.createElement('div');
        actions.className = 'admin-mobile-filter-actions';
        actions.innerHTML = '<button type="button" data-admin-mobile-filter-reset>重置</button><button type="button" data-admin-mobile-filter-submit>应用筛选</button>';
        content.append(field, actions);
        var sheet = api.openSheet({
            id: 'local-filter-' + snapshot.id,
            title: '搜索当前列表',
            subtitle: '即时筛选当前页面已加载的记录',
            content: content
        });
        if (!sheet) return false;
        var apply = function () {
            var query = input.value.trim();
            if (query) localQueries.set(snapshot.id, query);
            else localQueries.delete(snapshot.id);
            render(snapshot);
            sheet.close();
        };
        actions.querySelector('[data-admin-mobile-filter-submit]').addEventListener('click', apply);
        actions.querySelector('[data-admin-mobile-filter-reset]').addEventListener('click', function () {
            localQueries.delete(snapshot.id);
            render(snapshot);
            sheet.close();
        });
        input.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter') return;
            event.preventDefault();
            apply();
        });
        window.setTimeout(function () { if (input.isConnected) { input.focus(); input.select(); } }, 60);
        return true;
    }

    function setSelectionMode(host, selecting) {
        if (!host) return;
        host.classList.toggle('is-selecting', Boolean(selecting));
        alignReferenceCards(host);
        var trigger = host.querySelector('[data-admin-mobile-select-mode]');
        if (!trigger) return;
        trigger.setAttribute('aria-pressed', selecting ? 'true' : 'false');
        trigger.setAttribute('aria-expanded', selecting ? 'true' : 'false');
    }

    function renderToolbar(host, snapshot, recipe, desktop, rowEntries) {
        var toolbar = document.createElement('div');
        toolbar.className = 'admin-mobile-list-toolbar';
        if ((!api.shell || typeof api.shell.setSearch !== 'function') && ((snapshot.search && snapshot.search.instance) || (snapshot.state && snapshot.state.options && snapshot.state.options.length))) {
            var filter = document.createElement('button');
            var count = snapshotFilterCount(snapshot);
            filter.type = 'button'; filter.innerHTML = '<span class="material-icons-outlined" aria-hidden="true">tune</span><span>筛选' + (count ? '<b>' + count + '</b>' : '') + '</span>';
            filter.addEventListener('click', function () { openFilters(snapshot); });
            toolbar.appendChild(filter);
        }
        if (!(snapshot.search && snapshot.search.instance) && !(snapshot.state && snapshot.state.options && snapshot.state.options.length) && localSearchAvailable(snapshot, recipe) && snapshots.size > 1) {
            var localFilter = document.createElement('button');
            var localCount = localQueries.get(snapshot.id) ? 1 : 0;
            localFilter.type = 'button';
            localFilter.innerHTML = '<span class="material-icons-outlined" aria-hidden="true">search</span><span>筛选当前列表' + (localCount ? '<b>' + localCount + '</b>' : '') + '</span>';
            localFilter.addEventListener('click', function () { openLocalFilter(snapshot, recipe); });
            toolbar.appendChild(localFilter);
        }
        if (snapshot.selection && snapshot.selection.enabled === true && !host.classList.contains('is-selection-persistent') && selectableSelectionRows(snapshot, rowEntries).length > 0) {
            var select = document.createElement('button');
            select.type = 'button'; select.className = 'admin-mobile-toolbar-icon'; select.setAttribute('data-admin-mobile-select-mode', '');
            select.setAttribute('aria-controls', 'admin-mobile-selection-dock-' + snapshot.id);
            select.setAttribute('aria-pressed', host.classList.contains('is-selecting') ? 'true' : 'false');
            select.setAttribute('aria-expanded', host.classList.contains('is-selecting') ? 'true' : 'false');
            select.innerHTML = '<span class="material-icons-outlined" aria-hidden="true">checklist</span><span>选择</span>';
            select.addEventListener('click', function () {
                setSelectionMode(host, !host.classList.contains('is-selecting'));
                renderSelectionDock(host, snapshot, recipe, desktop, rowEntries);
            });
            toolbar.appendChild(select);
        }
        if ((snapshot.columns || []).some(function (column) { return column && column.sort === true; })) {
            var sort = document.createElement('button');
            sort.type = 'button'; sort.className = 'admin-mobile-toolbar-icon'; sort.innerHTML = '<span class="material-icons-outlined" aria-hidden="true">sort</span><span>排序</span>';
            sort.addEventListener('click', function () { openSort(snapshot); });
            toolbar.appendChild(sort);
        }
        ((((recipe || {}).actions || {}).toolbar) || []).forEach(function (item) {
            var card = desktop && desktop.closest ? desktop.closest('.card') : null;
            var target = item.selector && ((card && card.querySelector(item.selector)) || document.querySelector(item.selector));
            if (!controlAvailable(target)) return;
            var button = document.createElement('button');
            button.type = 'button'; button.classList.toggle('is-primary', item.role === 'primary'); button.classList.toggle('is-danger', item.danger === true);
            text(item.className || '').split(/\s+/).filter(Boolean).forEach(function (className) {
                button.classList.add(className);
            });
            var sourceDescription = text(target.getAttribute('data-admin-mobile-description') || item.description || '').trim();
            var buttonCopy = sourceDescription ? document.createElement('span') : null;
            var buttonLabel = document.createElement(sourceDescription ? 'strong' : 'span');
            var buttonDescription = sourceDescription ? document.createElement('small') : null;
            buttonLabel.textContent = text(target.getAttribute('data-admin-mobile-label') || item.label || '操作');
            if (buttonCopy) {
                buttonCopy.className = 'admin-mobile-store-enterprise-cta__copy';
                buttonLabel.className = 'admin-mobile-store-enterprise-cta__title';
                buttonDescription.className = 'admin-mobile-store-enterprise-cta__description';
                buttonDescription.textContent = sourceDescription;
                buttonCopy.append(buttonLabel, buttonDescription);
            }
            var sourceIcon = target.querySelector && target.querySelector('svg.md-message-send-icon');
            if (sourceIcon) {
                button.appendChild(sourceIcon.cloneNode(true));
            } else if (item.icon) {
                var configuredIcon = document.createElement('span');
                configuredIcon.className = 'material-icons-outlined';
                configuredIcon.setAttribute('aria-hidden', 'true');
                configuredIcon.textContent = text(item.icon);
                button.appendChild(configuredIcon);
            }
            button.appendChild(buttonCopy || buttonLabel);
            if (item.trailingIcon) {
                var trailingIcon = document.createElement('span');
                trailingIcon.className = 'material-icons-outlined admin-mobile-store-enterprise-cta__arrow';
                trailingIcon.setAttribute('aria-hidden', 'true');
                trailingIcon.textContent = text(item.trailingIcon);
                button.appendChild(trailingIcon);
            }
            button.setAttribute('data-admin-mobile-toolbar-proxy', '');
            var syncSourceState = function () {
                var sourceText = controlLabel(target);
                var busy = target.getAttribute('aria-busy') === 'true' ||
                    target.classList.contains('is-loading') ||
                    Boolean(target.querySelector && target.querySelector('.fa-spin, .spinner, .spinner-border, .spinner-grow')) ||
                    /(?:初始化任务|正在同步|同步完成，正在克隆|正在处理|加载中)/.test(sourceText);
                var disabled = busy || target.disabled || target.getAttribute('aria-disabled') === 'true' || target.classList.contains('disabled');
                button.disabled = disabled;
                button.setAttribute('aria-disabled', disabled ? 'true' : 'false');
                button.setAttribute('aria-busy', busy ? 'true' : 'false');
                var sourceLabel = text(target.getAttribute('data-admin-mobile-label') || '').trim();
                var dynamicLabel = busy || target.classList.contains('refresh') ? sourceText : (sourceLabel || text(item.label || '操作'));
                buttonLabel.textContent = dynamicLabel;
                if (buttonDescription) {
                    buttonDescription.textContent = text(target.getAttribute('data-admin-mobile-description') || item.description || '').trim();
                }
            };
            syncSourceState();
            var sourceObserver = new MutationObserver(syncSourceState);
            sourceObserver.observe(target, {attributes: true, childList: true, subtree: true, characterData: true});
            button.__adminMobileSourceObserver = sourceObserver;
            button.addEventListener('click', function () {
                if (button.disabled) return;
                runWithConfirmation(item.confirm, function () {
                    var result = clickSelector(item.selector, desktop);
                    window.setTimeout(syncSourceState, 0);
                    return result;
                });
            });
            toolbar.appendChild(button);
        });
        var pageControls = discoveredControls(desktop, recipe, false);
        if (pageControls.length) {
            var pageActions = pageControls.map(function (control) {
                return {label: controlLabel(control), danger: isDangerControl(control), run: function () { control.click(); }};
            });
            if (pageActions.length <= 2) {
                pageActions.forEach(function (item) {
                    var button = document.createElement('button'); button.type = 'button'; button.textContent = item.label; button.classList.toggle('is-danger', item.danger);
                    button.addEventListener('click', item.run); toolbar.appendChild(button);
                });
            } else {
                var more = document.createElement('button'); more.type = 'button'; more.className = 'admin-mobile-toolbar-icon'; more.innerHTML = '<span class="material-icons-outlined" aria-hidden="true">more_horiz</span><span>页面操作</span>';
                more.addEventListener('click', function () { api.openActions({id: 'page-actions-' + snapshot.id, title: '页面操作', actions: pageActions}); });
                toolbar.appendChild(more);
            }
        }
        if (toolbar.children.length) host.appendChild(toolbar);
    }

    function constrainSelectionToEntries(snapshot, entries) {
        if (!snapshot.__table || typeof snapshot.__table.setRowSelected !== 'function') return;
        var visibleRows = (entries || []).map(function (entry) { return entry.row; });
        (snapshot.selection && snapshot.selection.rows || []).forEach(function (selected) {
            var visible = visibleRows.some(function (row) { return sameSelectionRow(snapshot.selection || {}, selected, row); });
            if (!visible) snapshot.__table.setRowSelected(selected, false);
        });
    }

    function renderSelectionDock(host, snapshot, recipe, desktop, rowEntries) {
        var old = host.querySelector('.admin-mobile-selection-dock');
        if (old) old.remove();
        if (!host.classList.contains('is-selecting')) {
            setSelectionMode(host, false);
            return;
        }
        if (!snapshot.selection || snapshot.selection.enabled !== true) {
            setSelectionMode(host, false);
            return;
        }
        var dock = document.createElement('div');
        dock.className = 'admin-mobile-selection-dock';
        dock.id = 'admin-mobile-selection-dock-' + snapshot.id;
        var selectableRows = selectableSelectionRows(snapshot, rowEntries);
        if (!selectableRows.length) {
            setSelectionMode(host, false);
            return;
        }
        var selected = (rowEntries || []).filter(function (entry) { return rowSelectable(snapshot, entry.row, entry.index) && rowSelected(snapshot, entry.row, entry.index); }).length;
        var allSelected = !snapshot.selection.single && selectableRows.length > 0 && selectableRows.every(function (row) { return rowSelected(snapshot, row); });
        dock.innerHTML = '<div class="admin-mobile-selection-dock__summary">' +
            '<span class="admin-mobile-selection-dock__mark material-icons-outlined" aria-hidden="true">check_circle</span>' +
            '<strong role="status" aria-live="polite" aria-atomic="true">已选择 <b>' + selected + '</b> 项</strong>' +
            (snapshot.selection.single
                ? '<span class="admin-mobile-selection-dock__select-spacer" aria-hidden="true"></span>'
                : '<button type="button" class="admin-mobile-selection-dock__select-all" data-admin-mobile-select-all><span class="material-icons-outlined" aria-hidden="true">select_all</span><span>' + (allSelected ? '取消全选' : '全选本页') + '</span></button>') +
            '<button type="button" class="admin-mobile-selection-dock__done" data-admin-mobile-select-done aria-label="完成选择"><span class="material-icons-outlined" aria-hidden="true">close</span></button>' +
            '</div><div class="admin-mobile-selection-dock__actions"></div>';
        var actionHost = dock.querySelector('.admin-mobile-selection-dock__actions');
        var batchActions = [];
        ((((recipe || {}).actions || {}).batch) || []).forEach(function (item) {
            var card = desktop && desktop.closest ? desktop.closest('.card') : null;
            var target = item.selector && ((card && card.querySelector(item.selector)) || document.querySelector(item.selector));
            if (!controlAvailable(target)) return;
            batchActions.push({
                label: text(item.label || '批量操作'),
                danger: item.danger === true,
                disabled: selected < 1,
                run: function () {
                    return runWithConfirmation(item.confirm, function () {
                        constrainSelectionToEntries(snapshot, rowEntries);
                        return clickSelector(item.selector, desktop);
                    });
                }
            });
        });
        discoveredControls(desktop, recipe, true).forEach(function (control) {
            batchActions.push({
                label: controlLabel(control),
                danger: isDangerControl(control),
                disabled: selected < 1,
                run: function () {
                    constrainSelectionToEntries(snapshot, rowEntries);
                    control.click();
                }
            });
        });
        dock.classList.toggle('admin-mobile-selection-dock--has-actions', batchActions.length > 0);
        actionHost.hidden = batchActions.length < 1;
        var actionIcon = function (action) {
            var label = action.label || '';
            if (action.danger || /(?:删除|移除|清理)/.test(label)) return 'delete_outline';
            if (/(?:等级|会员)/.test(label)) return 'manage_accounts';
            if (/(?:锁定|停用|禁用|下架)/.test(label)) return 'block';
            if (/(?:解锁|启用|启动|上架)/.test(label)) return 'check_circle';
            if (/(?:生成|新增|导入)/.test(label)) return 'add_circle_outline';
            return 'tune';
        };
        var appendActionButton = function (action) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'admin-mobile-selection-dock__action';
            button.setAttribute('aria-label', action.label);
            button.innerHTML = '<span class="material-icons-outlined" aria-hidden="true">' + actionIcon(action) + '</span><span></span>';
            button.querySelector('span:last-child').textContent = action.label.replace(/^批量/, '');
            button.disabled = action.disabled === true;
            button.classList.toggle('is-danger', action.danger === true);
            button.addEventListener('click', action.run);
            actionHost.appendChild(button);
        };
        if (batchActions.length <= 2) batchActions.forEach(appendActionButton);
        else {
            var operations = document.createElement('button');
            operations.type = 'button';
            operations.className = 'admin-mobile-selection-dock__action';
            operations.disabled = selected < 1;
            operations.innerHTML = '<span class="material-icons-outlined" aria-hidden="true">tune</span><span>批量操作</span>';
            operations.addEventListener('click', function () {
                api.openActions({id: 'batch-actions-' + snapshot.id, title: '批量操作', subtitle: '已选 ' + selected + ' 项', actions: batchActions});
            });
            actionHost.appendChild(operations);
        }
        var selectAll = dock.querySelector('[data-admin-mobile-select-all]');
        if (selectAll) selectAll.addEventListener('click', function () {
            if (snapshot.__table && snapshot.__table.setRowSelected) selectableRows.forEach(function (row) { snapshot.__table.setRowSelected(row, !allSelected); });
        });
        dock.querySelector('[data-admin-mobile-select-done]').addEventListener('click', function () {
            var trigger = host.querySelector('[data-admin-mobile-select-mode]');
            setSelectionMode(host, false);
            dock.remove();
            if (trigger) trigger.focus();
        });
        host.appendChild(dock);
    }

    function renderPagination(host, snapshot) {
        var pagination = snapshot.pagination || {};
        if (!pagination.enabled || pagination.totalPages < 2) return;
        var footer = document.createElement('footer');
        footer.className = 'admin-mobile-pagination';
        if (host.classList.contains('admin-mobile-card-list--grouped-tree')) {
            footer.classList.add('admin-mobile-pagination--grouped');
            var pageSize = Math.max(1, Number(pagination.pageSize) || (snapshot.rows || []).length || 1);
            var start = Math.max(1, (Number(pagination.pageNumber) - 1) * pageSize + 1);
            var end = Math.min(Number(pagination.total) || start, start + Math.max(0, (snapshot.rows || []).length - 1));
            footer.innerHTML = '<span class="admin-mobile-pagination-summary">第 ' + start + ' - ' + end + ' 条 · 共 ' + pagination.total + ' 条</span><span class="admin-mobile-pagination-controls"><button type="button" aria-label="上一页"><span class="material-icons-outlined" aria-hidden="true">chevron_left</span></button><strong aria-current="page">' + pagination.pageNumber + '</strong><button type="button" aria-label="下一页"><span class="material-icons-outlined" aria-hidden="true">chevron_right</span></button></span>';
        } else {
            footer.innerHTML = '<button type="button" aria-label="上一页"><span class="material-icons-outlined" aria-hidden="true">chevron_left</span></button><span>第 <strong>' + pagination.pageNumber + '</strong> / ' + pagination.totalPages + ' 页 · ' + pagination.total + ' 条</span><button type="button" aria-label="下一页"><span class="material-icons-outlined" aria-hidden="true">chevron_right</span></button>';
        }
        var buttons = footer.querySelectorAll('button');
        buttons[0].disabled = pagination.pageNumber <= 1;
        buttons[1].disabled = pagination.pageNumber >= pagination.totalPages;
        buttons[0].addEventListener('click', function () { snapshot.__table && snapshot.__table.reload({pageNumber: pagination.pageNumber - 1}); });
        buttons[1].addEventListener('click', function () { snapshot.__table && snapshot.__table.reload({pageNumber: pagination.pageNumber + 1}); });
        host.appendChild(footer);
    }

    function renderLoadState(cards, snapshot) {
        var state = snapshot.status || {};
        if (state.loading === true || state.status === 'loading') {
            cards.innerHTML = '<div class="admin-mobile-load-state" role="status" aria-live="polite"><span class="admin-mobile-load-spinner" aria-hidden="true"></span><strong>正在加载</strong><small>请稍候</small></div>';
            return true;
        }
        if (state.error || state.status === 'error') {
            var error = state.error || {};
            var message = text(error.message || '数据加载失败');
            cards.innerHTML = '<div class="admin-mobile-load-state admin-mobile-load-state--error" role="alert"><span class="material-icons-outlined" aria-hidden="true">cloud_off</span><strong>加载失败</strong><small></small><button type="button">重新加载</button></div>';
            cards.querySelector('small').textContent = message || (error.status ? '请求状态：' + error.status : '请检查网络后重试');
            cards.querySelector('button').addEventListener('click', function () {
                var retry = state.retry || state.refresh || snapshot.refresh;
                if (typeof retry === 'function') retry();
            });
            return true;
        }
        return false;
    }

    function sameSelectionRow(selection, left, right) {
        if (left === right) return true;
        var field = selection.idField || selection.field || 'id';
        var leftValue = rawValue(left, field);
        var rightValue = rawValue(right, field);
        return leftValue !== undefined && rightValue !== undefined && leftValue == rightValue;
    }

    function selectionRowState(snapshot, row, index) {
        var selection = snapshot.selection || {};
        var states = selection.rowStates || [];
        return states.find(function (state) {
            if (state.row === row || (index !== undefined && state.index === index)) return true;
            return sameSelectionRow(selection, state.row, row);
        }) || null;
    }

    function rowSelectable(snapshot, row, index) {
        var state = selectionRowState(snapshot, row, index);
        if (state) return state.selectable !== false && state.disabled !== true;
        var disabledRows = (snapshot.selection && snapshot.selection.disabledRows) || [];
        return !disabledRows.some(function (disabled) { return sameSelectionRow(snapshot.selection || {}, disabled, row); });
    }

    function selectableSelectionRows(snapshot, entries) {
        entries = Array.isArray(entries)
            ? entries
            : (snapshot.rows || []).map(function (row, index) { return {row: row, index: index}; });
        return entries.filter(function (entry) {
            return rowSelectable(snapshot, entry.row, entry.index);
        }).map(function (entry) { return entry.row; });
    }

    function rowSelected(snapshot, row, index) {
        var selection = snapshot.selection || {};
        var state = selectionRowState(snapshot, row, index);
        if (state && state.selected !== undefined) return state.selectable !== false && state.disabled !== true && state.selected === true;
        return (selection.rows || []).some(function (selected) {
            return sameSelectionRow(selection, selected, row);
        });
    }

    function treeClassValue(element, parent) {
        if (!element || !element.classList) return '';
        var value = '';
        Array.from(element.classList).some(function (className) {
            var match = parent ? className.match(/^treegrid-parent-(.+)$/) : className.match(/^treegrid-(?!parent-)(.+)$/);
            if (!match) return false;
            value = match[1];
            return true;
        });
        return value;
    }

    function treeKey(value) {
        return value === null || value === undefined ? '' : String(value).trim();
    }

    function treeRowLabel(row, recipe) {
        var fields = recipeFields(recipe, 'primary').concat(['name', 'title', 'id']);
        var label = '';
        fields.some(function (field) {
            var value = text(rawValue(row, field));
            if (!value || value === '-') return false;
            label = value;
            return true;
        });
        return label;
    }

    function treeMetadata(snapshot, recipe) {
        if (!recipe || (recipe.pageType !== 'tree-list' && recipe.tree !== true)) return null;
        var rows = snapshot.rows || [];
        var rowElements = snapshot.element ? Array.from(snapshot.element.querySelectorAll('tbody tr')) : [];
        var elementsByIndex = new Map();
        rowElements.forEach(function (element, position) {
            var index = parseInt(element.getAttribute('data-index'), 10);
            elementsByIndex.set(Number.isNaN(index) ? position : index, element);
        });
        var nodes = rows.map(function (row, index) {
            var element = elementsByIndex.get(index) || rowElements[index] || null;
            var ownKey = treeClassValue(element, false) || treeKey(rawValue(row, 'id'));
            var parentKey = treeClassValue(element, true);
            if (!parentKey) {
                var parentValue = rawValue(row, 'pid');
                if (parentValue === undefined) parentValue = rawValue(row, 'parent_id');
                if (parentValue === undefined) parentValue = rawValue(row, 'parent.id');
                parentKey = treeKey(parentValue);
            }
            var renderedDepth = element ? element.querySelectorAll('.treegrid-indent').length : 0;
            var modelDepth = parseInt(rawValue(row, '_level'), 10);
            return {
                row: row,
                index: index,
                ownKey: treeKey(ownKey),
                parentKey: treeKey(parentKey),
                label: treeRowLabel(row, recipe),
                fallbackDepth: Math.max(renderedDepth, Number.isNaN(modelDepth) ? 0 : modelDepth),
                parent: null,
                children: [],
                depth: null
            };
        });
        var byTreeKey = new Map();
        var byId = new Map();
        nodes.forEach(function (node) {
            if (node.ownKey) byTreeKey.set(node.ownKey, node);
            var id = treeKey(rawValue(node.row, 'id'));
            if (id) byId.set(id, node);
        });
        var resolveDepth = function (node, trail) {
            if (node.depth !== null) return node.depth;
            trail = trail || [];
            var parent = node.parentKey && (byTreeKey.get(node.parentKey) || byId.get(node.parentKey));
            if (parent === node || trail.indexOf(parent) >= 0) parent = null;
            node.parent = parent || null;
            if (!parent) node.depth = Math.min(Math.max(node.fallbackDepth, 0), 9);
            else node.depth = Math.min(resolveDepth(parent, trail.concat(node)) + 1, 9);
            return node.depth;
        };
        nodes.forEach(function (node) { resolveDepth(node); });
        nodes.forEach(function (node) {
            if (node.parent) node.parent.children.push(node);
        });
        return nodes;
    }

    function treeLevelLabel(depth) {
        return ['一级分类', '二级分类', '三级分类', '四级分类', '五级分类'][depth] || ('第' + (depth + 1) + '级分类');
    }

    function groupedTreeLevelLabel(depth) {
        return depth === 0 ? '一级分类' : ('第 ' + (depth + 1) + ' 级分类');
    }

    function enhanceNativeTableInteractions(table, recipe) {
        if (!table || !recipe || recipe.id !== 'admin-photo-album-picker') return;
        table.querySelectorAll('.photo-album-selected').forEach(function (image, index) {
            if (image.dataset.adminMobileAlbumAction === 'true') return;
            image.dataset.adminMobileAlbumAction = 'true';
            image.setAttribute('role', 'button');
            image.setAttribute('tabindex', '0');
            image.setAttribute('aria-label', '选择图片 ' + (index + 1));
            image.addEventListener('keydown', function (event) {
                if (event.repeat || (event.key !== 'Enter' && event.key !== ' ')) return;
                event.preventDefault();
                image.click();
            });
        });
    }

    function renderTreeContext(card, node, referenceEnabled, recipe) {
        if (!node) return;
        card.classList.add('admin-mobile-data-card--tree');
        card.setAttribute('data-admin-mobile-tree-depth', node.depth);
        card.setAttribute('data-admin-mobile-tree-index', node.index);
        card.setAttribute('data-admin-mobile-tree-key', node.ownKey);
        card.setAttribute('data-admin-mobile-tree-parent-key', node.parent ? node.parent.ownKey : '');
        card.setAttribute('data-admin-mobile-tree-has-children', node.children.length ? 'true' : 'false');
        card.style.setProperty('--admin-mobile-tree-depth', node.depth);
        var indentStep = Number(recipe && recipe.treeIndentStep);
        if (!Number.isFinite(indentStep) || indentStep < 0) indentStep = 10;
        var indentMax = Number(recipe && recipe.treeIndentMax);
        if (!Number.isFinite(indentMax) || indentMax < 0) indentMax = 26;
        card.style.setProperty('--admin-mobile-tree-indent', Math.min(node.depth * indentStep, indentMax) + 'px');
        if (recipe && recipe.treeLayout === 'grouped') {
            card.classList.add('admin-mobile-data-card--tree-grouped');
            var header = card.querySelector('header');
            var media = header && header.querySelector('.admin-mobile-card-media');
            var heading = header && header.querySelector('.admin-mobile-card-heading');
            var leading = document.createElement('span');
            leading.className = 'admin-mobile-tree-leading';
            leading.setAttribute('data-admin-mobile-tree-leading', '');
            leading.setAttribute('aria-hidden', 'true');
            if (node.depth > 0 && !node.children.length) {
                leading.innerHTML = '<span class="material-icons-outlined" aria-hidden="true">subdirectory_arrow_right</span>';
            }
            if (header) header.insertBefore(leading, media || heading || header.firstChild);
            if (heading) {
                var title = heading.querySelector('strong');
                var subtitle = heading.querySelector('small');
                var level = document.createElement('span');
                level.className = 'admin-mobile-tree-level';
                level.textContent = groupedTreeLevelLabel(node.depth);
                heading.insertBefore(level, title || heading.firstChild);
                if (subtitle) {
                    var metadata = document.createElement('span');
                    metadata.className = 'admin-mobile-tree-meta';
                    var parent = document.createElement('span');
                    parent.className = 'admin-mobile-tree-parent';
                    parent.textContent = node.parent && node.parent.label ? node.parent.label : '根目录';
                    var separator = document.createElement('i');
                    separator.textContent = '·';
                    separator.setAttribute('aria-hidden', 'true');
                    metadata.append(parent, separator, subtitle);
                    heading.appendChild(metadata);
                }
            }
            return;
        }
        if (recipe && recipe.treeContext === false) return;
        if (referenceEnabled) {
            var subtitle = card.querySelector('.admin-mobile-card-heading small');
            if (subtitle) {
                var parent = node.parent && node.parent.label ? '上级：' + node.parent.label : (node.depth ? '隶属于上一级' : '顶级分类');
                subtitle.textContent = treeLevelLabel(node.depth) + ' · ' + parent;
            }
            return;
        }
        var context = document.createElement('div');
        context.className = 'admin-mobile-tree-context';
        context.innerHTML = '<span class="material-icons-outlined" aria-hidden="true"></span><strong></strong><small></small>';
        context.querySelector('.material-icons-outlined').textContent = node.depth ? 'subdirectory_arrow_right' : 'account_tree';
        context.querySelector('strong').textContent = treeLevelLabel(node.depth);
        context.querySelector('small').textContent = node.parent && node.parent.label ? '上级：' + node.parent.label : (node.depth ? '隶属于上一级' : '顶级分类');
        card.querySelector('header').insertAdjacentElement('afterend', context);
    }

    function treeCollapseState(snapshotId) {
        var key = String(snapshotId || '');
        var collapsed = treeCollapseStates.get(key);
        if (!collapsed) {
            collapsed = new Set();
            treeCollapseStates.set(key, collapsed);
        }
        return collapsed;
    }

    function treeNodeHiddenByCollapse(node, collapsed) {
        var parent = node && node.parent;
        var visited = [];
        while (parent && visited.indexOf(parent) < 0) {
            if (collapsed.has(parent.ownKey)) return true;
            visited.push(parent);
            parent = parent.parent;
        }
        return false;
    }

    function updateTreeCollapsePresentation(host, nodes, collapsed) {
        if (!host || !Array.isArray(nodes) || !collapsed) return;
        host.querySelectorAll('[data-admin-mobile-tree-index]').forEach(function (card) {
            var index = parseInt(card.getAttribute('data-admin-mobile-tree-index'), 10);
            var node = Number.isNaN(index) ? null : nodes[index];
            if (!node) return;
            card.classList.toggle('is-hidden-by-tree', treeNodeHiddenByCollapse(node, collapsed));
            var toggle = card.querySelector('[data-admin-mobile-tree-toggle]');
            if (!toggle) return;
            var expanded = !collapsed.has(node.ownKey);
            toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
            toggle.setAttribute('aria-label', (expanded ? '收起分类：' : '展开分类：') + (node.label || '未命名分类'));
            toggle.title = expanded ? '收起分类' : '展开分类';
            var toggleLabel = toggle.querySelector('[data-admin-mobile-tree-toggle-label]');
            if (toggleLabel) {
                var childCount = Math.max(1, parseInt(toggle.getAttribute('data-admin-mobile-tree-child-count'), 10) || 0);
                toggleLabel.textContent = (expanded ? '收起 ' : '展开 ') + childCount + ' 个下级分类';
            }
            var icon = toggle.querySelector('.material-icons-outlined');
            if (icon) {
                icon.textContent = expanded
                    ? (toggle.getAttribute('data-admin-mobile-tree-expanded-icon') || 'remove')
                    : (toggle.getAttribute('data-admin-mobile-tree-collapsed-icon') || 'add');
            }
            card.classList.toggle('is-tree-collapsed', !expanded);
        });
    }

    function renderTreeToggle(card, node, host, nodes, collapsed, recipe) {
        if (!node || !node.ownKey || !node.children.length || !collapsed) return;
        var toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'admin-mobile-tree-toggle';
        toggle.setAttribute('data-admin-mobile-tree-toggle', '');
        toggle.setAttribute('data-admin-mobile-tree-child-count', node.children.length);
        toggle.setAttribute('data-admin-mobile-tree-expanded-icon', text(recipe && recipe.treeToggleExpandedIcon) || 'remove');
        toggle.setAttribute('data-admin-mobile-tree-collapsed-icon', text(recipe && recipe.treeToggleCollapsedIcon) || 'add');
        var placement = recipe && recipe.treeTogglePlacement;
        toggle.innerHTML = placement === 'disclosure'
            ? '<span data-admin-mobile-tree-toggle-label></span><span class="material-icons-outlined" aria-hidden="true"></span>'
            : '<span class="material-icons-outlined" aria-hidden="true"></span>';
        toggle.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            if (collapsed.has(node.ownKey)) collapsed.delete(node.ownKey);
            else collapsed.add(node.ownKey);
            updateTreeCollapsePresentation(host, nodes, collapsed);
        });
        var media = placement === 'media' ? card.querySelector('.admin-mobile-card-media') : null;
        if (media) {
            media.classList.add('admin-mobile-card-media--tree-toggle');
            toggle.classList.add('admin-mobile-tree-toggle--media');
            media.appendChild(toggle);
        } else if (placement === 'leading') {
            var leading = card.querySelector('[data-admin-mobile-tree-leading]');
            if (leading) {
                leading.removeAttribute('aria-hidden');
                toggle.classList.add('admin-mobile-tree-toggle--leading');
                leading.replaceChildren(toggle);
            }
        } else if (placement === 'disclosure') {
            toggle.classList.add('admin-mobile-tree-disclosure');
            card.querySelector('header').insertAdjacentElement('afterend', toggle);
        } else {
            card.querySelector('header').appendChild(toggle);
        }
    }

    function render(snapshot) {
        if (!api.isEnabled() || !snapshot || !snapshot.element) return;
        var table = snapshot.element;
        var desktop = table.closest('.bootstrap-table') || table;
        var id = snapshot.id;
        var tableId = String(table.id || '').replace(/^#/, '') || id;
        var context = {queryUrl: snapshot.queryUrl, tableId: tableId, snapshot: snapshot};
        var recipe = api.matchRecipe(context);
        var host = document.querySelector('[data-admin-mobile-table="' + id + '"]');
        if (recipe && recipe.nativeTable === true) {
            if (host) {
                cleanupToolbarProxies(host);
                host.remove();
            }
            desktop.classList.remove('admin-mobile-desktop-table');
            var nativeTableCard = desktop.closest('.card');
            if (nativeTableCard) nativeTableCard.classList.remove('admin-mobile-table-card');
            localQueries.delete(snapshot.id);
            if (api.shell && typeof api.shell.clearSearch === 'function') api.shell.clearSearch('table:' + snapshot.id);
            enhanceNativeTableInteractions(table, recipe);
            return;
        }
        if (!host) {
            host = document.createElement('section');
            host.className = 'admin-mobile-card-list';
            host.setAttribute('data-admin-mobile-table', id);
            desktop.insertAdjacentElement('afterend', host);
        }
        host.classList.add('admin-mobile-card-list--unified');
        desktop.classList.add('admin-mobile-desktop-table');
        var tableCard = desktop.closest('.card');
        var mixedForm = tableCard && Array.from(tableCard.querySelectorAll('form')).some(function (form) {
            return !form.classList.contains('search-query') && form.contains(desktop);
        });
        if (tableCard && !mixedForm) tableCard.classList.add('admin-mobile-table-card');
        var pageRecipe = typeof api.getActiveRecipe === 'function' ? api.getActiveRecipe() : null;
        snapshot = Object.assign({}, snapshot, {__adminMobilePageRecipe: pageRecipe});
        var usesReferenceCards = referenceCardEnabled(recipe, pageRecipe);
        host.classList.toggle('admin-mobile-card-list--reference', usesReferenceCards);
        host.setAttribute('data-admin-mobile-card-layout', recipe && recipe.cardLayout === 'ledger' ? 'ledger' : (usesReferenceCards ? 'reference' : 'standard'));
        var cardVariant = recipeVariant(recipe);
        applyCardVariant(host, 'admin-mobile-card-list', cardVariant);
        snapshot = applyRecipePresentation(snapshot, recipe);
        configureShellSearch(snapshot, recipe);
        var columns = visibleColumns(snapshot);
        var primaryDefinitions = recipeDefinitions(recipe, 'primary');
        var primaryFields = recipeFields(recipe, 'primary');
        var primary = columns.find(function (column) { return primaryFields.indexOf(column.field) >= 0; }) || columns.find(function (column) { return ['name', 'title', 'username', 'trade_no', 'id'].indexOf(column.field) >= 0; }) || columns[0];
        var statusDefinitions = recipeDefinitions(recipe, 'status').filter(function (definition) { return primaryFields.indexOf(definition.field) < 0; });
        var metricDefinitions = recipeDefinitions(recipe, 'metrics').concat(recipeDefinitions(recipe, 'summary')).filter(function (definition) { return primaryFields.indexOf(definition.field) < 0; });
        if (!statusDefinitions.length && !metricDefinitions.length && (!recipe || recipe.autoMetrics !== false)) metricDefinitions = columns.filter(function (column) { return column !== primary; }).slice(0, 4).map(function (column) { return {field: column.field, label: text(column.title || column.field)}; });
        var prominentDefinition = usesReferenceCards ? referenceProminentDefinition(recipe, statusDefinitions, metricDefinitions) : null;
        var compactDefinitions = compactCardDefinitions(recipe, statusDefinitions, metricDefinitions, {
            reference: usesReferenceCards,
            prominent: prominentDefinition
        });
        var treeNodes = treeMetadata(snapshot, recipe);
        var treeCollapsible = Boolean(recipe && recipe.treeCollapsible === true && treeNodes);
        var collapsedTreeNodes = treeCollapsible ? treeCollapseState(snapshot.id) : null;
        if (collapsedTreeNodes) {
            var validTreeKeys = new Set(treeNodes.map(function (node) { return node.ownKey; }).filter(Boolean));
            Array.from(collapsedTreeNodes).forEach(function (key) {
                if (!validTreeKeys.has(key)) collapsedTreeNodes.delete(key);
            });
        }
        host.classList.toggle('admin-mobile-card-list--collapsible-tree', treeCollapsible);
        host.classList.toggle('admin-mobile-card-list--grouped-tree', Boolean(recipe && recipe.treeLayout === 'grouped'));
        var rowEntries = (snapshot.rows || []).map(function (row, index) { return {row: row, index: index}; });
        var localQuery = String(localQueries.get(snapshot.id) || '').trim().toLocaleLowerCase();
        host.classList.toggle('is-tree-searching', treeCollapsible && Boolean(localQuery));
        if (localQuery) {
            rowEntries = rowEntries.filter(function (entry) {
                return cardSearchText(recipe, snapshot, columns, entry, primary, primaryDefinitions, statusDefinitions, metricDefinitions, treeNodes && treeNodes[entry.index]).indexOf(localQuery) >= 0;
            });
        }
        var persistentSelection = Boolean(snapshot.selection && snapshot.selection.enabled === true && (
            (recipe && recipe.selectionPersistent === true) ||
            (snapshot.selection.single && table.id === 'pro-unbind-table')
        ));
        var wasSelecting = Boolean(snapshot.selection && snapshot.selection.enabled === true && host.classList.contains('is-selecting'));
        cleanupToolbarProxies(host);
        host.innerHTML = '';
        host.classList.toggle('is-selecting', wasSelecting);
        host.classList.toggle('is-selection-persistent', persistentSelection);
        renderToolbar(host, snapshot, recipe, desktop, rowEntries);
        renderStoreDiscovery(host, snapshot, recipe);
        var cards = document.createElement('div');
        cards.className = 'admin-mobile-card-items';
        host.appendChild(cards);
        if (renderLoadState(cards, snapshot)) {
            renderSelectionDock(host, snapshot, recipe, desktop, []);
            return;
        }
        if (!rowEntries.length) {
            cards.innerHTML = '<div class="admin-mobile-empty"><span class="material-icons-outlined" aria-hidden="true">inbox</span><strong>' + (localQuery ? '没有匹配的记录' : '暂无数据') + '</strong><small>' + (localQuery ? '换个关键词或重置当前筛选' : '调整筛选条件后再试') + '</small></div>';
            renderPagination(host, snapshot);
            renderSelectionDock(host, snapshot, recipe, desktop, []);
            return;
        }
        rowEntries.forEach(function (entry) {
            var row = entry.row;
            var index = entry.index;
            var card = document.createElement('article');
            card.className = 'admin-mobile-data-card';
            applyCardVariant(card, 'admin-mobile-data-card', cardVariant);
            var cardCta = recipe && recipe.cardCta;
            var cardCtaVisible = Boolean(cardCta);
            if (cardCtaVisible && typeof cardCta.show === 'function') {
                try { cardCtaVisible = cardCta.show(row, index, snapshot) !== false; } catch (error) { cardCtaVisible = false; }
            }
            card.classList.toggle('admin-mobile-data-card--cta', cardCtaVisible);
            var heading = primaryDefinitions.length ? primaryDefinitionValue(snapshot, primaryDefinitions[0], columns, row, index, recipe) : (primary ? definitionValue(snapshot, mobileColumnDefinition(primary), columns, row, index, recipe) : ('记录 ' + (index + 1)));
            var recordDefinition = recipeDefinitions(recipe, 'recordTitle')[0];
            var recordHeading = recordDefinition ? definitionValue(snapshot, recordDefinition, columns, row, index, recipe) : heading;
            var recordHeadingField = recordDefinition && recordDefinition.field || (primaryDefinitions[0] && primaryDefinitions[0].field) || (primary && primary.field) || '';
            var recordTitle = (text(recordHeading) || text(heading) || ('记录 ' + (index + 1))).replace(/\s+/g, ' ');
            var subtitle = cardSubtitle(recipe, snapshot, columns, row, index, primaryDefinitions, heading);
            var selectionType = snapshot.selection && snapshot.selection.type === 'radio' ? 'radio' : 'checkbox';
            card.innerHTML = '<header><label class="admin-mobile-select"><input type="' + selectionType + '" name="admin-mobile-select-' + id + '" aria-label="选择此项"><span></span></label><span class="admin-mobile-card-media"><span class="material-icons-outlined" aria-hidden="true"></span></span><button type="button" class="admin-mobile-card-heading" data-admin-mobile-card-detail><strong></strong><small></small></button><button type="button" data-admin-mobile-card-more aria-label="更多操作"><span class="material-icons-outlined" aria-hidden="true">more_horiz</span></button></header><div class="admin-mobile-card-status" hidden></div><dl class="admin-mobile-card-metrics"></dl><footer class="admin-mobile-card-actions"></footer>';
            var headingControl = card.querySelector('.admin-mobile-card-heading');
            var staticCard = Boolean(recipe && recipe.staticCard === true);
            if (persistentSelection || staticCard) {
                var staticHeading = document.createElement('div');
                staticHeading.className = headingControl.className;
                staticHeading.innerHTML = headingControl.innerHTML;
                if (staticCard) staticHeading.removeAttribute('data-admin-mobile-card-detail');
                headingControl.replaceWith(staticHeading);
                headingControl = staticHeading;
            }
            var headingField = (primaryDefinitions[0] && primaryDefinitions[0].field) || (primary && primary.field) || '';
            var identifierHeading = /(?:^id$|(?:^|_)(?:id|no|code|secret|serial|uuid)$)/i.test(headingField);
            headingControl.classList.toggle('admin-mobile-card-heading--identifier', identifierHeading);
            setDefinitionInlineContent(
                headingControl.querySelector('strong'),
                primaryDefinitions[0] || (primary ? mobileColumnDefinition(primary) : null),
                row,
                heading || '-'
            );
            var subtitleControl = card.querySelector('header small');
            var subtitleDefinition = recipeDefinitions(recipe, 'subtitle')[0];
            renderCardSubtitle(
                subtitleControl,
                recipe,
                snapshot,
                row,
                subtitle && subtitle !== heading ? subtitle : '',
                function () { return cardSubtitle(recipe, snapshot, columns, row, index, primaryDefinitions, heading); }
            );
            if (subtitleDefinition && subtitleDefinition.copyField && headingControl.tagName === 'BUTTON' && subtitleControl) {
                var headingShell = document.createElement('div');
                headingShell.className = 'admin-mobile-card-heading-shell';
                headingControl.parentNode.insertBefore(headingShell, headingControl);
                headingShell.append(headingControl, subtitleControl);
            }
            var primaryCopy = recipe && recipe.primaryCopy;
            var primaryCopyValue = primaryCopy && primaryCopy.copyField ? rawValue(row, primaryCopy.copyField) : null;
            var primaryCopyEnabled = headingControl.tagName === 'BUTTON' && primaryCopy && primaryCopy.copyField && primaryCopyValue != null && String(primaryCopyValue).trim() !== '';
            if (primaryCopyEnabled) {
                var primaryCopyLabel = text(primaryCopy.label || '复制内容') || '复制内容';
                var primaryCopyHeadingControl = document.createElement('div');
                primaryCopyHeadingControl.className = headingControl.className;
                while (headingControl.firstChild) primaryCopyHeadingControl.appendChild(headingControl.firstChild);
                headingControl.replaceWith(primaryCopyHeadingControl);
                headingControl = primaryCopyHeadingControl;
                headingControl.classList.add('admin-mobile-card-heading--copyable');

                var primaryDetailControl = document.createElement('button');
                primaryDetailControl.type = 'button';
                primaryDetailControl.className = 'admin-mobile-card-heading-detail-control';
                primaryDetailControl.setAttribute('data-admin-mobile-card-detail', '');
                primaryDetailControl.setAttribute('aria-label', '查看详细信息：' + recordTitle);
                primaryDetailControl.title = '查看详细信息';
                headingControl.insertBefore(primaryDetailControl, headingControl.firstChild);

                var primaryCopyButton = document.createElement('button');
                primaryCopyButton.type = 'button';
                primaryCopyButton.className = 'admin-mobile-card-heading-copy-button';
                primaryCopyButton.setAttribute('data-admin-mobile-primary-copy', '');
                primaryCopyButton.setAttribute('aria-label', primaryCopyLabel + '：' + String(primaryCopyValue).trim());
                primaryCopyButton.title = primaryCopyLabel;
                var primaryCopyIcon = document.createElement('span');
                primaryCopyIcon.className = 'material-icons-outlined admin-mobile-card-heading-copy-icon';
                primaryCopyIcon.setAttribute('aria-hidden', 'true');
                primaryCopyIcon.textContent = 'content_copy';
                primaryCopyButton.appendChild(primaryCopyIcon);
                var primaryCopyHeading = headingControl.querySelector('strong');
                var primaryCopyHeadingText = primaryCopyHeading.textContent || '-';
                var primaryCopyCharacters = window.Intl && typeof window.Intl.Segmenter === 'function'
                    ? Array.from(new window.Intl.Segmenter(undefined, {granularity: 'grapheme'}).segment(primaryCopyHeadingText), function (part) { return part.segment; })
                    : Array.from(primaryCopyHeadingText);
                var primaryCopyTail = document.createElement('span');
                primaryCopyTail.className = 'admin-mobile-card-heading-copy-tail';
                primaryCopyTail.append(document.createTextNode(primaryCopyCharacters.pop() || '-'), primaryCopyButton);
                primaryCopyHeading.textContent = primaryCopyCharacters.join('');
                primaryCopyHeading.appendChild(primaryCopyTail);
                primaryCopyButton.addEventListener('click', function (event) {
                    event.preventDefault();
                    event.stopPropagation();
                    copyConfiguredField(primaryCopy, row);
                });
            }
            var liveHeadingDefinition = primaryDefinitions[0] || mobileColumnDefinition(primary);
            var liveHeadingControl = headingControl.querySelector('strong');
            if (
                !primaryCopyEnabled &&
                liveHeadingControl &&
                relativeTimeDefinition(recipe, liveHeadingDefinition, snapshot && snapshot.__adminMobilePageRecipe) &&
                relativeTimeText(heading)
            ) {
                bindLiveTextControl(liveHeadingControl, function () {
                    return primaryDefinitions.length
                        ? primaryDefinitionValue(snapshot, primaryDefinitions[0], columns, row, index, recipe)
                        : definitionValue(snapshot, liveHeadingDefinition, columns, row, index, recipe);
                });
            }
            var treeNode = treeNodes && treeNodes[index];
            renderTreeContext(card, treeNode, usesReferenceCards, recipe);
            if (treeCollapsible) renderTreeToggle(card, treeNode, host, treeNodes, collapsedTreeNodes, recipe);
            var media = card.querySelector('.admin-mobile-card-media');
            var mediaDescriptor = mediaDefinition(recipe, row);
            media.querySelector('.material-icons-outlined').textContent = mediaDescriptor && mediaDescriptor.fallbackIcon || mediaIcon(recipe);
            media.classList.add('admin-mobile-card-media--' + (mediaDescriptor && mediaDescriptor.shape === 'circle' ? 'circle' : 'rounded'));
            if (mediaDescriptor && mediaDescriptor.type === 'payment') media.classList.add('admin-mobile-card-media--payment');
            var source = mediaDescriptorSource(mediaDescriptor, row, snapshot, columns, index);
            if (source) {
                var image = document.createElement('img');
                image.alt = '';
                image.loading = 'lazy';
                image.addEventListener('load', function () {
                    media.classList.add('has-image');
                    var mediaReservedForTreeToggle = Boolean(
                        treeNode &&
                        treeNode.children.length &&
                        recipe &&
                        recipe.treeTogglePlacement === 'media'
                    );
                    if (!mediaReservedForTreeToggle && (!mediaDescriptor || mediaDescriptor.preview !== false)) {
                        enableMediaPreview(media, source, recordTitle);
                    }
                }, {once: true});
                image.addEventListener('error', function () { image.remove(); }, {once: true});
                image.src = source;
                media.appendChild(image);
            }
            var status = card.querySelector('.admin-mobile-card-status');
            compactDefinitions.forEach(function (definition) {
                var value = definitionValue(snapshot, definition, columns, row, index, recipe);
                if (!value || value === '-') return;
                var badge = document.createElement('span');
                var tone = definitionTone(definition, value, row, index, snapshot, statusTone);
                var badgeValue = value;
                if (definition.__adminMobileMetric && definition.compactLabel !== false) {
                    var badgeLabel = text(definition.compactLabel || definition.label || definition.field);
                    if (badgeLabel) badgeValue = badgeLabel + ' ' + value;
                }
                badge.className = 'admin-mobile-status-chip admin-mobile-status-chip--' + tone;
                badge.setAttribute('data-admin-mobile-tone', tone);
                badge.setAttribute('data-admin-mobile-field', String(definition.field || ''));
                badge.setAttribute('data-admin-mobile-source', definition.__adminMobileMetric ? 'metric' : 'status');
                badge.title = text(definition.label || definition.field);
                badge.innerHTML = (definition.dot === false ? '' : '<i aria-hidden="true"></i>') + '<span></span>';
                var badgeText = badge.querySelector('span');
                badgeText.textContent = badgeValue;
                if (relativeTimeDefinition(recipe, definition, snapshot && snapshot.__adminMobilePageRecipe) && relativeTimeText(value)) {
                    bindLiveTextControl(badgeText, function () {
                        var nextValue = definitionValue(snapshot, definition, columns, row, index, recipe);
                        if (definition.__adminMobileMetric && definition.compactLabel !== false) {
                            var nextLabel = text(definition.compactLabel || definition.label || definition.field);
                            if (nextLabel) return nextLabel + ' ' + nextValue;
                        }
                        return nextValue;
                    });
                }
                status.appendChild(badge);
            });
            status.hidden = status.children.length < 1;
            var list = card.querySelector('.admin-mobile-card-metrics');
            if (list) list.remove();
            var checkbox = card.querySelector('input');
            var moreControl = card.querySelector('[data-admin-mobile-card-more]');
            checkbox.setAttribute('aria-label', '选择记录：' + recordTitle);
            moreControl.setAttribute('aria-label', recordTitle + '的更多操作');
            var inlineSwitchRendered = renderInlineSwitch(card, recipe, snapshot, columns, row, moreControl, recordTitle);
            var selectable = rowSelectable(snapshot, row, index);
            checkbox.checked = selectable && rowSelected(snapshot, row, index);
            checkbox.disabled = !selectable;
            checkbox.setAttribute('aria-disabled', selectable ? 'false' : 'true');
            card.classList.toggle('is-selection-disabled', !selectable);
            if (!selectable) checkbox.closest('label').title = '此项不可选择';
            checkbox.addEventListener('change', function () {
                if (!selectable) {
                    checkbox.checked = false;
                    return;
                }
                if (snapshot.__table && snapshot.__table.setRowSelected) snapshot.__table.setRowSelected(row, checkbox.checked);
            });
            var rowActions = availableActions(snapshot, row);
            configuredFieldActions(recipe, row).forEach(function (action) {
                if (!rowActions.some(function (current) { return current.id === action.id; })) rowActions.push(action);
            });
            var usesRecordSheet = recordSheetEnabled(recipe, snapshot && snapshot.__adminMobilePageRecipe);
            var openRecord = function () {
                return openRecordSheet(recipe, snapshot, columns, row, index, recordTitle, recordHeadingField, subtitle, rowActions);
            };
            var openCard = function () {
                var inlineField = recipe && recipe.openInlineField;
                var inlineColumn = inlineField && columns.find(function (column) {
                    return String(column.field) === String(inlineField) && columnAvailable(column, row);
                });
                if (inlineColumn && snapshot.__table && typeof snapshot.__table.updateField === 'function') {
                    return inlineInputEditor(snapshot, row, inlineColumn);
                }
                var directActionId = recipe && recipe.openAction;
                var directAction = directActionId && rowActions.find(function (action) { return action.id === directActionId; });
                if (directAction) return invokeRecipeAction(snapshot, recipe, directAction, row, index);
                if (cardCta) return openCardCta(recipe, snapshot, columns, row, index, heading, headingField, subtitle, rowActions);
                if (usesRecordSheet) return openRecord();
                return openDetails(recipe, snapshot, columns, row, index, recordHeading, recordHeadingField);
            };
            if (!inlineSwitchRendered) {
                renderLedgerCard(card, recipe, snapshot, columns, row, index, moreControl);
                renderReferenceCard(card, recipe, snapshot, columns, row, index, moreControl, statusDefinitions, metricDefinitions, usesReferenceCards, prominentDefinition);
            }
            var detailControl = card.querySelector('[data-admin-mobile-card-detail]');
            if (detailControl) detailControl.addEventListener('click', openCard);
            var actionFooter = card.querySelector('.admin-mobile-card-actions');
            if (actionFooter) actionFooter.remove();
            if (inlineSwitchRendered) {
                // The inline switch is the only row interaction for static configuration lists.
            } else if (cardCtaVisible) {
                var ctaLabel = text(cardCta.label || '查看');
                moreControl.classList.add('admin-mobile-card-cta');
                moreControl.textContent = ctaLabel;
                moreControl.setAttribute('aria-label', ctaLabel + recordTitle);
                moreControl.addEventListener('click', openCard);
            } else if (cardCta) {
                moreControl.remove();
            } else if (usesRecordSheet) {
                moreControl.addEventListener('click', openRecord);
            } else {
                moreControl.addEventListener('click', function () {
                    var remaining = orderedActions(recipe, rowActions, 'more');
                    var actions = [{label: '查看详细信息', icon: 'visibility', run: function () { openDetails(recipe, snapshot, columns, row, index, recordHeading, recordHeadingField); }}]
                        .concat(inlineActions(snapshot, columns, row))
                        .concat(remaining.map(function (action) {
                        var configured = recipeAction(recipe, 'primary', action.id) || recipeAction(recipe, 'more', action.id);
                        return {label: actionLabel(recipe, action), icon: configured && configured.icon || action.icon, danger: actionDanger(recipe, action), run: function () { return invokeRecipeAction(snapshot, recipe, action, row, index); }};
                    }));
                    var actionTitle = text((recipe && recipe.title) || '记录').replace(/管理$/, '') + '操作';
                    if (actions.length) api.openActions({title: actionTitle, subtitle: recordHeading, actions: actions});
                });
            }
            cards.appendChild(card);
        });
        if (treeCollapsible) updateTreeCollapsePresentation(host, treeNodes, collapsedTreeNodes);
        alignReferenceCards(host);
        renderPagination(host, snapshot);
        renderSelectionDock(host, snapshot, recipe, desktop, rowEntries);
    }

    function payload(event) {
        var detail = event.detail || {};
        if (detail.detail) detail = detail.detail;
        var snapshot = detail.snapshot || detail;
        if (detail.table && snapshot) snapshot.__table = detail.table;
        return snapshot;
    }

    function onReady(event) {
        var snapshot = payload(event);
        if (!snapshot || !snapshot.id) return;
        snapshots.set(snapshot.id, snapshot);
        render(snapshot);
        (api.workflows || []).forEach(function (workflow) {
            var tableId = snapshot.element && snapshot.element.id ? snapshot.element.id : snapshot.id;
            if (typeof workflow.onTable === 'function') workflow.onTable(snapshot, api.getContext({recipe: api.matchRecipe({queryUrl: snapshot.queryUrl, tableId: tableId})}));
        });
    }

    function onDestroy(event) {
        var snapshot = payload(event);
        if (!snapshot || !snapshot.id) return;
        snapshots.delete(snapshot.id);
        localQueries.delete(snapshot.id);
        treeCollapseStates.delete(String(snapshot.id || ''));
        var host = document.querySelector('[data-admin-mobile-table="' + snapshot.id + '"]');
        if (host) {
            setSelectionMode(host, false);
            cleanupToolbarProxies(host);
            host.remove();
        }
        if (api.shell && typeof api.shell.clearSearch === 'function') api.shell.clearSearch('table:' + snapshot.id);
        var table = snapshot.element;
        var desktop = table && table.closest ? (table.closest('.bootstrap-table') || table) : null;
        if (desktop) desktop.classList.remove('admin-mobile-desktop-table');
        var card = table && table.closest ? table.closest('.card') : null;
        if (card) card.classList.remove('admin-mobile-table-card');
    }

    function onViewportChange() {
        if (!mounted) return;
        alignReferenceCards(document);
    }

    function clear() {
        stopLiveTextTimer();
        clearReferenceAlignment();
        document.querySelectorAll('[data-admin-mobile-table]').forEach(function (node) {
            setSelectionMode(node, false);
            cleanupToolbarProxies(node);
            node.remove();
        });
        document.querySelectorAll('.admin-mobile-desktop-table').forEach(function (node) { node.classList.remove('admin-mobile-desktop-table'); });
        document.querySelectorAll('.admin-mobile-table-card').forEach(function (node) { node.classList.remove('admin-mobile-table-card'); });
        snapshots.clear();
        localQueries.clear();
        treeCollapseStates.clear();
        if (api.shell && typeof api.shell.clearSearch === 'function') api.shell.clearSearch();
    }

    function removePresentation() {
        stopLiveTextTimer();
        clearReferenceAlignment();
        document.querySelectorAll('[data-admin-mobile-table]').forEach(function (node) {
            setSelectionMode(node, false);
            cleanupToolbarProxies(node);
            node.remove();
        });
        document.querySelectorAll('.admin-mobile-desktop-table').forEach(function (node) { node.classList.remove('admin-mobile-desktop-table'); });
        document.querySelectorAll('.admin-mobile-table-card').forEach(function (node) { node.classList.remove('admin-mobile-table-card'); });
        if (api.shell && typeof api.shell.clearSearch === 'function') api.shell.clearSearch();
    }

    function rebuild(reason) {
        clear();
        if (typeof Table === 'undefined' || typeof Table.getInstances !== 'function') return;
        Array.from(Table.getInstances()).forEach(function (table) {
            if (!table || table.isDestroyed || typeof table.getMobileSnapshot !== 'function') return;
            var element = table.$table && typeof table.$table.get === 'function' ? table.$table.get(0) : null;
            if (!element || !element.isConnected) {
                if (typeof table.destroy === 'function') table.destroy();
                return;
            }
            var snapshot;
            try { snapshot = table.getMobileSnapshot(reason || 'mobile-rebuild'); } catch (error) { console.error(error); return; }
            if (!snapshot || !snapshot.id) return;
            snapshot.__table = table;
            snapshots.set(snapshot.id, snapshot);
            if (mounted && api.isEnabled()) render(snapshot);
        });
    }

    api.relativeTimeTimestamp = relativeTimeTimestamp;
    api.formatRelativeTime = formatRelativeTime;

    api.fallback = {
        mount: function () {
            if (mounted) return;
            mounted = true;
            if (window.jQuery) {
                window.jQuery(document)
                    .off('.adminMobileFallback')
                    .on('admin:table:ready.adminMobileFallback admin:table:update.adminMobileFallback', onReady)
                    .on('admin:table:destroy.adminMobileFallback', onDestroy)
                    .on('admin:mobile:viewportchange.adminMobileFallback', onViewportChange);
            } else {
                document.addEventListener('admin:table:ready', onReady);
                document.addEventListener('admin:table:update', onReady);
                document.addEventListener('admin:table:destroy', onDestroy);
                document.addEventListener('admin:mobile:viewportchange', onViewportChange);
            }
            rebuild('mobile-mount');
        },
        refresh: function () { rebuild('mobile-refresh'); },
        rebuild: rebuild,
        clear: clear,
        unmount: function () {
            if (!mounted) return;
            mounted = false;
            if (window.jQuery) window.jQuery(document).off('.adminMobileFallback');
            else {
                document.removeEventListener('admin:table:ready', onReady);
                document.removeEventListener('admin:table:update', onReady);
                document.removeEventListener('admin:table:destroy', onDestroy);
                document.removeEventListener('admin:mobile:viewportchange', onViewportChange);
            }
            clear();
        }
    };
}(window, document));
