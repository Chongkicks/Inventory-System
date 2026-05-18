(() => {
    // Categories that calculate stock from individual size inputs.
    const sizeTrackedCategories = new Set(['PE Uniform', 'Uniform']);

    // Keep same-page hash navigation visually active.
    const navs = document.querySelectorAll('.topbar nav');

    navs.forEach((nav) => {
        const hashLinks = Array.from(nav.querySelectorAll('a[href^="#"]'));

        if (!hashLinks.length) {
            return;
        }

        const syncActiveState = () => {
            const activeLink = hashLinks.find((link) => link.getAttribute('href') === window.location.hash);

            if (!activeLink) {
                return;
            }

            hashLinks.forEach((link) => {
                const isActive = link === activeLink;
                link.classList.toggle('is-active', isActive);

                if (isActive) {
                    link.setAttribute('aria-current', 'location');
                } else {
                    link.removeAttribute('aria-current');
                }
            });
        };

        hashLinks.forEach((link) => {
            link.addEventListener('click', () => {
                window.requestAnimationFrame(syncActiveState);
            });
        });

        window.addEventListener('hashchange', syncActiveState);
        syncActiveState();
    });

    // Confirm logout so users do not leave the app by accident.
    document.querySelectorAll('[data-logout-link]').forEach((link) => {
        link.addEventListener('click', (event) => {
            if (!window.confirm('Are you sure you want to log out?')) {
                event.preventDefault();
            }
        });
    });

    // Auto-submit select filters when their value changes.
    document.querySelectorAll('[data-auto-submit-form]').forEach((form) => {
        const submitForm = () => {
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
                return;
            }

            form.submit();
        };

        form.querySelectorAll('[data-auto-submit]').forEach((control) => {
            control.addEventListener('change', submitForm);
        });
    });

    // Debounce live search forms to reduce unnecessary page reloads.
    document.querySelectorAll('[data-live-search-form]').forEach((form) => {
        const input = form.querySelector('[data-live-search-input]');

        if (!input) {
            return;
        }

        let lastSubmittedValue = input.value.trim();
        let searchTimer = null;

        const submitSearch = () => {
            const nextValue = input.value.trim();

            if (nextValue === lastSubmittedValue) {
                return;
            }

            lastSubmittedValue = nextValue;
            form.classList.add('is-searching');

            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
                return;
            }

            form.submit();
        };

        input.addEventListener('input', () => {
            window.clearTimeout(searchTimer);
            searchTimer = window.setTimeout(submitSearch, 450);
        });

        input.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape' || input.value === '') {
                return;
            }

            input.value = '';
            window.clearTimeout(searchTimer);
            submitSearch();
        });
    });

    // Add/edit product forms switch between total quantity and per-size quantity modes.
    const productForm = document.querySelector('.add-product-form');

    if (productForm) {
        const categorySelect = productForm.querySelector('select[name="category"]');
        const quantityField = productForm.querySelector('[data-product-quantity-field]');
        const quantityInput = quantityField ? quantityField.querySelector('input[name="quantity"]') : null;
        const priceInput = productForm.querySelector('input[name="price"]');
        const uniformSizePanel = productForm.querySelector('[data-uniform-size-panel]');
        const totalQtyValue = productForm.querySelector('[data-uniform-total-qty]');
        const totalCostValue = productForm.querySelector('[data-uniform-total-cost]');
        const uniformSizeInputs = Array.from(productForm.querySelectorAll('[data-uniform-size-input]'));

        if (categorySelect && quantityInput && uniformSizePanel && uniformSizeInputs.length) {
            const formatCurrency = (value) => `\u20B1${value.toFixed(2)}`;

            // Keep total quantity/cost synced with size fields and price.
            const syncProductTotals = () => {
                const total = uniformSizeInputs.reduce((sum, input) => {
                    const value = parseInt(input.value, 10);
                    return sum + (Number.isFinite(value) ? value : 0);
                }, 0);

                const priceValue = priceInput ? parseFloat(priceInput.value) : 0;
                const safePrice = Number.isFinite(priceValue) ? Math.max(0, priceValue) : 0;
                const totalCost = total * safePrice;

                if (sizeTrackedCategories.has(categorySelect.value)) {
                    quantityInput.value = String(total);
                }

                if (totalQtyValue) {
                    totalQtyValue.textContent = String(total);
                }

                if (totalCostValue) {
                    totalCostValue.textContent = formatCurrency(totalCost);
                }
            };

            // Hide the flat quantity input when the selected category uses sizes.
            const syncProductMode = () => {
                const isUniform = sizeTrackedCategories.has(categorySelect.value);

                uniformSizePanel.hidden = !isUniform;

                if (quantityField) {
                    quantityField.hidden = isUniform;
                }

                quantityInput.readOnly = isUniform;
                quantityInput.required = !isUniform;

                if (quantityField) {
                    quantityField.classList.toggle('is-uniform', isUniform);
                }

                if (isUniform) {
                    syncProductTotals();
                }
            };

            uniformSizeInputs.forEach((input) => {
                input.addEventListener('input', syncProductTotals);
            });

            if (priceInput) {
                priceInput.addEventListener('input', syncProductTotals);
            }

            categorySelect.addEventListener('change', syncProductMode);
            syncProductMode();
        }
    }

    // Product edit modal can open from a data-* enriched table action.
    const productModal = document.querySelector('[data-product-modal]');

    if (productModal) {
        const modalForm = productModal.querySelector('.add-product-form');
        const backdrop = productModal.querySelector('[data-product-modal-backdrop]');
        const closeControl = productModal.querySelector('[data-product-modal-close]');
        const productIdInput = modalForm ? modalForm.querySelector('input[name="productID"]') : null;
        const productNameInput = modalForm ? modalForm.querySelector('input[name="productName"]') : null;
        const categorySelect = modalForm ? modalForm.querySelector('select[name="category"]') : null;
        const quantityInput = modalForm ? modalForm.querySelector('input[name="quantity"]') : null;
        const priceInput = modalForm ? modalForm.querySelector('input[name="price"]') : null;
        const sizeInputs = modalForm ? Array.from(modalForm.querySelectorAll('[data-uniform-size-input]')) : [];
        const title = modalForm ? modalForm.querySelector('#product-form-title') : null;
        const closeHref = backdrop ? backdrop.getAttribute('href') : '';
        const shouldNavigateOnClose = new URLSearchParams(window.location.search).has('edit');

        // Product size data is stored in data-product-sizes as JSON.
        const parseSizeQuantities = (value) => {
            if (!value) {
                return {};
            }

            try {
                const parsed = JSON.parse(value);
                return parsed && typeof parsed === 'object' ? parsed : {};
            } catch (error) {
                return {};
            }
        };

        // Toggle modal visibility and the body scroll lock.
        const setHiddenState = (isHidden) => {
            productModal.hidden = isHidden;

            if (isHidden) {
                document.body.classList.remove('modal-open');
            } else {
                document.body.classList.add('modal-open');
            }
        };

        // Copy product data from the clicked edit link into the modal form.
        const populateModal = (trigger) => {
            const productSizes = parseSizeQuantities(trigger.dataset.productSizes || '');
            const isSizeTracked = trigger.dataset.sizeTracked === '1';

            if (productIdInput) {
                productIdInput.value = trigger.dataset.productId || '';
            }

            if (productNameInput) {
                productNameInput.value = trigger.dataset.productName || '';
            }

            if (categorySelect) {
                categorySelect.value = trigger.dataset.productCategory || '';
            }

            if (quantityInput) {
                quantityInput.value = trigger.dataset.productQuantity || '0';
            }

            if (priceInput) {
                priceInput.value = trigger.dataset.productPrice || '0.00';
            }

            sizeInputs.forEach((input) => {
                const match = input.name.match(/\[(.*)\]/);
                const sizeLabel = match ? match[1] : '';
                input.value = String(productSizes[sizeLabel] ?? 0);
            });

            if (title) {
                title.textContent = 'Edit Product';
            }

            setHiddenState(false);

            if (categorySelect) {
                categorySelect.dispatchEvent(new Event('change', { bubbles: true }));
            }

            sizeInputs.forEach((input) => {
                input.dispatchEvent(new Event('input', { bubbles: true }));
            });

            if (priceInput) {
                priceInput.dispatchEvent(new Event('input', { bubbles: true }));
            }

            window.requestAnimationFrame(() => {
                if (productNameInput) {
                    productNameInput.focus();
                }
            });
        };

        // Close in-place unless the modal was opened by a URL parameter.
        const closeModal = (event) => {
            if (event) {
                event.preventDefault();
            }

            if (shouldNavigateOnClose && closeHref) {
                window.location.href = closeHref;
                return;
            }

            setHiddenState(true);
        };

        document.addEventListener('click', (event) => {
            const trigger = event.target.closest('[data-edit-product]');

            if (!trigger) {
                return;
            }

            event.preventDefault();
            populateModal(trigger);
        });

        if (backdrop) {
            backdrop.addEventListener('click', closeModal);
        }

        if (closeControl) {
            closeControl.addEventListener('click', closeModal);
        }

        window.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !productModal.hidden) {
                closeModal(event);
            }
        });

        if (!productModal.hidden) {
            document.body.classList.add('modal-open');
        }
    }

    // Stock transaction modal handles stock-in/stock-out without leaving the dashboard.
    const stockModal = document.querySelector('[data-stock-modal]');

    if (stockModal) {
        const openTriggers = document.querySelectorAll('[data-open-stock-modal]');
        const backdrop = stockModal.querySelector('[data-stock-modal-backdrop]');
        const closeControl = stockModal.querySelector('[data-stock-modal-close]');
        const closeHref = backdrop ? backdrop.getAttribute('href') : '';
        const shouldNavigateOnClose = new URLSearchParams(window.location.search).has('stock');

        // Toggle modal visibility and body scroll lock.
        const setHiddenState = (isHidden) => {
            stockModal.hidden = isHidden;

            if (isHidden) {
                document.body.classList.remove('modal-open');
            } else {
                document.body.classList.add('modal-open');
            }
        };

        const openModal = (event) => {
            event.preventDefault();
            setHiddenState(false);
        };

        const closeModal = (event) => {
            event.preventDefault();

            if (shouldNavigateOnClose && closeHref) {
                window.location.href = closeHref;
                return;
            }

            setHiddenState(true);
        };

        openTriggers.forEach((trigger) => {
            trigger.addEventListener('click', openModal);
        });

        if (backdrop) {
            backdrop.addEventListener('click', closeModal);
        }

        if (closeControl) {
            closeControl.addEventListener('click', closeModal);
        }

        window.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !stockModal.hidden) {
                closeModal(event);
            }
        });

        // Stock form data comes from the selected product option.
        const productSelect = stockModal.querySelector('[data-stock-product-select]');
        const typeSelect = stockModal.querySelector('[data-stock-type-select]');
        const sizePanel = stockModal.querySelector('[data-stock-size-panel]');
        const quantityField = stockModal.querySelector('[data-stock-quantity-field]');
        const quantityInput = quantityField ? quantityField.querySelector('input[name="quantity"]') : null;
        const sizeInputs = Array.from(stockModal.querySelectorAll('[data-stock-size-input]'));
        const totalQtyValue = stockModal.querySelector('[data-stock-total-qty]');
        const totalCostValue = stockModal.querySelector('[data-stock-total-cost]');

        // Parse saved size quantities from the selected product option.
        const parseSizeQuantities = (value) => {
            if (!value) {
                return {};
            }

            try {
                const parsed = JSON.parse(value);
                return parsed && typeof parsed === 'object' ? parsed : {};
            } catch (error) {
                return {};
            }
        };

        // Recalculate stock quantity/cost summary for size-tracked stock forms.
        const syncStockTotals = () => {
            const selectedOption = productSelect ? productSelect.selectedOptions[0] : null;
            const priceValue = selectedOption ? parseFloat(selectedOption.dataset.productPrice || '0') : 0;
            const safePrice = Number.isFinite(priceValue) ? Math.max(0, priceValue) : 0;
            const total = sizeInputs.reduce((sum, input) => {
                const value = parseInt(input.value, 10);
                return sum + (Number.isFinite(value) ? Math.max(0, value) : 0);
            }, 0);

            if (totalQtyValue) {
                totalQtyValue.textContent = String(total);
            }

            if (totalCostValue) {
                totalCostValue.textContent = `\u20B1${(total * safePrice).toFixed(2)}`;
            }
        };

        if (productSelect && sizePanel && quantityField && quantityInput && sizeInputs.length) {
            // Switch the stock form between flat quantity and per-size quantity inputs.
            const syncStockSizeField = () => {
                const selectedOption = productSelect.selectedOptions[0];
                const needsSize = selectedOption && selectedOption.dataset.sizeTracked === '1';
                const isStockOut = typeSelect && typeSelect.value === 'stock_out';
                const currentSizes = parseSizeQuantities(selectedOption ? selectedOption.dataset.productSizes : '');

                sizePanel.hidden = !needsSize;
                quantityField.hidden = needsSize;
                quantityInput.required = !needsSize;

                sizeInputs.forEach((input) => {
                    const sizeLabel = input.dataset.sizeLabel || '';
                    const currentQuantity = parseInt(currentSizes[sizeLabel] ?? 0, 10);
                    const safeCurrentQuantity = Number.isFinite(currentQuantity) ? Math.max(0, currentQuantity) : 0;

                    input.disabled = !needsSize;
                    input.placeholder = String(safeCurrentQuantity);

                    if (isStockOut) {
                        input.max = String(safeCurrentQuantity);
                    } else {
                        input.removeAttribute('max');
                    }

                    if (!needsSize) {
                        input.value = '0';
                    } else if (parseInt(input.value, 10) > safeCurrentQuantity && isStockOut) {
                        input.value = String(safeCurrentQuantity);
                    }
                });

                syncStockTotals();
            };

            productSelect.addEventListener('change', () => {
                sizeInputs.forEach((input) => {
                    input.value = '0';
                });
                syncStockSizeField();
            });
            sizeInputs.forEach((input) => {
                input.addEventListener('input', syncStockTotals);
            });

            if (typeSelect) {
                typeSelect.addEventListener('change', syncStockSizeField);
            }

            syncStockSizeField();
        }

        if (!stockModal.hidden) {
            document.body.classList.add('modal-open');
        }
    }

    const notificationDuration = 5000;
    let notificationStack = null;
    let realtimePusher = null;

    // Return a trimmed string or a safe fallback.
    const textValue = (value, fallback) => {
        const normalized = typeof value === 'string' ? value.trim() : '';
        return normalized || fallback;
    };

    // Create the notification stack once, when the first message appears.
    const ensureNotificationStack = () => {
        if (notificationStack) {
            return notificationStack;
        }

        notificationStack = document.createElement('div');
        notificationStack.className = 'realtime-notification-stack';
        notificationStack.setAttribute('aria-live', 'polite');
        notificationStack.setAttribute('aria-atomic', 'false');
        document.body.appendChild(notificationStack);

        return notificationStack;
    };

    // Play the leaving animation before removing the notification.
    const closeNotification = (notification) => {
        notification.classList.add('is-leaving');
        window.setTimeout(() => notification.remove(), 180);
    };

    // Replace a page section with fresh HTML after realtime/AJAX updates.
    const replaceSectionFromDocument = (nextDocument, selector) => {
        const currentElement = document.querySelector(selector);
        const nextElement = nextDocument.querySelector(selector);

        if (currentElement && nextElement) {
            currentElement.innerHTML = nextElement.innerHTML;
        }
    };

    // Pull the current page again and refresh only dynamic table/select fragments.
    const syncRealtimeSections = () => {
        fetch(window.location.href, {
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then((response) => response.text())
            .then((html) => {
                const nextDocument = new DOMParser().parseFromString(html, 'text/html');

                [
                    '#products .dashboard-products-table-wrap',
                    '#products .pagination',
                    '.history-table tbody',
                    '.reports-page .pagination',
                    '.users-table tbody',
                    '.users-list-panel .pagination',
                    '[data-stock-product-select]'
                ].forEach((selector) => replaceSectionFromDocument(nextDocument, selector));
            })
            .catch((error) => {
                console.error('Realtime section sync failed.', error);
            });
    };

    // Show a snackbar, optionally refreshing page fragments.
    const showPopupNotification = (payload = {}) => {
        const data = payload && typeof payload === 'object' ? payload : {};
        const config = window.InventoryRealtime || {};
        const audienceRoles = Array.isArray(data.audienceRoles) ? data.audienceRoles : [];

        if (audienceRoles.length && !audienceRoles.includes(config.role || 'guest')) {
            return;
        }

        const allowedLevels = new Set(['info', 'success', 'warning', 'danger']);
        const level = allowedLevels.has(data.level) ? data.level : 'info';
        const stack = ensureNotificationStack();
        const notification = document.createElement('section');
        notification.className = `realtime-notification realtime-snackbar is-${level}`;
        notification.setAttribute('role', level === 'warning' || level === 'danger' ? 'alert' : 'status');

        const status = document.createElement('span');
        status.className = 'realtime-snackbar-status';
        status.setAttribute('aria-hidden', 'true');

        const content = document.createElement('div');
        content.className = 'realtime-snackbar-content';

        const title = document.createElement('strong');
        title.textContent = textValue(data.title, 'Inventory update');

        const message = document.createElement('p');
        message.textContent = textValue(data.message, 'Inventory data changed.');

        content.append(title, message);

        const closeButton = document.createElement('button');
        closeButton.type = 'button';
        closeButton.className = 'realtime-notification-close';
        closeButton.setAttribute('aria-label', 'Close notification');
        closeButton.textContent = 'x';
        closeButton.addEventListener('click', () => closeNotification(notification));

        notification.append(status, content, closeButton);
        stack.prepend(notification);

        while (stack.children.length > 4) {
            stack.lastElementChild.remove();
        }

        window.setTimeout(() => closeNotification(notification), notificationDuration);

        if (data.refresh) {
            syncRealtimeSections();
        }
    };

    // Convert redirect ?message= values into the same snackbar UI used by AJAX.
    const showRedirectNotification = () => {
        const params = new URLSearchParams(window.location.search);
        const message = params.get('message');

        if (!message) {
            return;
        }

        const normalized = message.toLowerCase();
        const errorTokens = [
            'access denied',
            'already exists',
            'cannot',
            'exceeds',
            'expired',
            'invalid',
            'not found',
            'required',
            'security',
            'unable'
        ];
        const isError = errorTokens.some((token) => normalized.includes(token));

        showPopupNotification({
            title: isError ? 'Action needed' : 'Action complete',
            message,
            level: isError ? 'danger' : 'success',
            refresh: false
        });

        params.delete('message');
        const nextQuery = params.toString();
        const nextUrl = `${window.location.pathname}${nextQuery ? `?${nextQuery}` : ''}${window.location.hash}`;
        window.history.replaceState(window.history.state, '', nextUrl);
    };

    // Hide modal UI after an AJAX action succeeds.
    const closeAjaxModal = (form) => {
        const modal = form.closest('[data-product-modal], [data-stock-modal]');

        if (!modal) {
            return;
        }

        modal.hidden = true;
        document.body.classList.remove('modal-open');

        const params = new URLSearchParams(window.location.search);
        const hadModalParam = params.has('edit') || params.has('stock');

        params.delete('edit');
        params.delete('stock');

        if (hadModalParam) {
            const nextQuery = params.toString();
            const nextUrl = `${window.location.pathname}${nextQuery ? `?${nextQuery}` : ''}${window.location.hash}`;
            window.history.replaceState(window.history.state, '', nextUrl);
        }
    };

    // Reset non-modal forms and fire events so dependent totals update.
    const resetAjaxForm = (form) => {
        if (form.classList.contains('is-modal')) {
            return;
        }

        form.reset();

        form.querySelectorAll('select, input').forEach((control) => {
            control.dispatchEvent(new Event('change', { bubbles: true }));
            control.dispatchEvent(new Event('input', { bubbles: true }));
        });
    };

    // Submit forms with fetch so the page can update without a full reload.
    const handleAjaxSubmit = (event) => {
        const form = event.target;

        if (!(form instanceof HTMLFormElement) || !form.matches('[data-ajax-form]') || event.defaultPrevented) {
            return;
        }

        event.preventDefault();

        if (form.dataset.ajaxBusy === '1') {
            return;
        }

        const submitter = event.submitter instanceof HTMLElement ? event.submitter : form.querySelector('[type="submit"]');
        const action = form.getAttribute('action') || window.location.href;
        const method = (form.getAttribute('method') || 'GET').toUpperCase();
        const body = new FormData(form);

        form.dataset.ajaxBusy = '1';

        if (submitter) {
            submitter.setAttribute('aria-busy', 'true');
            submitter.disabled = true;
        }

        fetch(action, {
            method,
            body,
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then((response) => {
                if (response.redirected) {
                    window.location.href = response.url;
                    return null;
                }

                return response.json()
                    .catch(() => ({
                        ok: response.ok,
                        message: response.ok ? 'Action complete' : 'Unable to complete action',
                        refresh: response.ok
                    }));
            })
            .then((payload) => {
                if (!payload) {
                    return;
                }

                const ok = payload.ok !== false;

                showPopupNotification({
                    title: ok ? 'Action complete' : 'Action needed',
                    message: payload.message || (ok ? 'Action complete' : 'Unable to complete action'),
                    level: ok ? 'success' : 'danger',
                    refresh: false
                });

                if (ok && payload.reset) {
                    resetAjaxForm(form);
                }

                if (ok && payload.closeModal) {
                    closeAjaxModal(form);
                }

                if (payload.refresh !== false) {
                    syncRealtimeSections();
                }
            })
            .catch(() => {
                showPopupNotification({
                    title: 'Action needed',
                    message: 'Unable to complete action',
                    level: 'danger',
                    refresh: false
                });
            })
            .finally(() => {
                delete form.dataset.ajaxBusy;

                if (submitter) {
                    submitter.removeAttribute('aria-busy');
                    submitter.disabled = false;
                }
            });
    };

    // Subscribe to Pusher notifications when the backend printed config.
    const initRealtimeNotifications = () => {
        const config = window.InventoryRealtime;

        if (!config || !config.key || typeof window.Pusher !== 'function') {
            return;
        }

        try {
            realtimePusher = new window.Pusher(config.key, {
                cluster: config.cluster || 'ap1',
                forceTLS: true
            });
            const channel = realtimePusher.subscribe(config.channel || 'inventory-channel');
            channel.bind(config.event || 'inventory-notification', showPopupNotification);
        } catch (error) {
            console.error('Realtime notifications failed to start.', error);
        }
    };

    showRedirectNotification();
    initRealtimeNotifications();

    // Add the Pusher socket id to forms so the sender can be excluded from duplicate pushes.
    document.addEventListener('submit', (event) => {
        const form = event.target;

        if (!(form instanceof HTMLFormElement) || !realtimePusher || !realtimePusher.connection) {
            return;
        }

        const socketId = realtimePusher.connection.socket_id || '';

        if (!socketId) {
            return;
        }

        let socketInput = form.querySelector('input[name="pusher_socket_id"]');

        if (!socketInput) {
            socketInput = document.createElement('input');
            socketInput.type = 'hidden';
            socketInput.name = 'pusher_socket_id';
            form.appendChild(socketInput);
        }

        socketInput.value = socketId;
    }, true);

    document.addEventListener('submit', handleAjaxSubmit);

})();
