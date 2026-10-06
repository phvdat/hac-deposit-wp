jQuery(document).ready(function ($) {
    function showAlert(title, text, type) {
        return Swal.fire({
            title: title,
            text: text,
            type: type,
            confirmButtonText: 'OK'
        })
    }

    function showError(text) {
        return showAlert("Error", text, "error");
    }

    function showConfirm(message) {
        return Swal.fire({
            title: 'Are you sure?',
            html: message,
            type: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#3085d6',
            cancelButtonColor: '#d33',
            confirmButtonText: 'Yes!'
        });
    }

    function showSuccess(message) {
        return Swal.fire(
            'Success!',
            message,
            'success'
        )
    }

    function csSanitizeShieldDomain(value) {
        if (!value) return '';
        var v = String(value).replace(/\s+/g, '');
        v = v.replace(/\/+$/, '');
        if (/^http:\/\//i.test(v)) {
            v = 'https://' + v.replace(/^http:\/\//i, '');
        }
        return v;
    }

    function csCheckShieldHealth(domain, type) {
        return new Promise(function (resolve) {
            if (!domain) { resolve('UNREACHABLE'); return; }
            jQuery.post(cs_ajax_object.ajax_url, {
                action: 'mecom_gateway_paypal_action',
                command: 'checkShieldHealth',
                proxyUrl: domain,
                type: type
            }, function (response) {
                try {
                    var data = typeof response === 'string' ? JSON.parse(response) : response;
                    if (data && data.status === 'OK') resolve('OK');
                    else if (data && data.status === 'EMPTY_LIVE_KEY') resolve('EMPTY_LIVE_KEY');
                    else resolve('UNREACHABLE');
                } catch (e) { resolve('UNREACHABLE'); }
            }).fail(function () { resolve('UNREACHABLE'); });
        });
    }


    function revertPreviousRotationMethod(selectedRotationMethod) {
        if (selectedRotationMethod === 'by_time') {
            $('#rotationByAmount').prop("checked", true);
        } else if (selectedRotationMethod === 'by_amount') {
            $('#rotationByTime').prop("checked", true);
        }
    }

    function addProxy() {
        var rotationMethod = $('input[name="rotationMethod"]:checked').val();
        var newProxyUrl = csSanitizeShieldDomain($('#new-proxy-url').val());
        var newRotationValue = $('#new-rotation-value').val();

        if (!newProxyUrl || !newRotationValue.trim()) {
            showError('Please fill in all required field!');
            return;
        }
        $('#new-proxy-url').val(newProxyUrl);

        Swal.fire({ title: 'Checking shield...', text: 'Please wait', allowOutsideClick: false, allowEscapeKey: false, showConfirmButton: false });
        Swal.showLoading();
        csCheckShieldHealth(newProxyUrl, 'paypal').then(function (status) {
            Swal.close();
            if (status === 'UNREACHABLE') {
                showError("Can't connect to shield domain " + newProxyUrl + ". Please check the URL and try again.");
                return;
            }
            var proceed = function () {
                csSubmitAddProxy(newProxyUrl, rotationMethod, newRotationValue);
            };
            if (status === 'EMPTY_LIVE_KEY') {
                Swal.fire({
                    title: 'Warning',
                    text: 'This shield has no live key configured. It will only work in sandbox mode. Add anyway?',
                    type: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Add anyway',
                    cancelButtonText: 'Cancel'
                }).then(function (result) {
                    if (result.value) proceed();
                });
            } else {
                proceed();
            }
        });
    }

    function csSubmitAddProxy(newProxyUrl, rotationMethod, newRotationValue) {
        var data = {
            'action': 'mecom_gateway_paypal_action',
            'command': 'addNewProxy',
            'rotationMethod': rotationMethod,
            'proxyUrl': newProxyUrl,
            'rotationValue': newRotationValue
        };

        jQuery.post(cs_ajax_object.ajax_url, data, function (response) {
            var dataJson = JSON.parse(response);
            if (!dataJson.success) {
                showError('Failed to add proxy. Please try again!');
                return;
            }

            $('#new-proxy-url').val('');
            $('#new-rotation-value').val('');

            $('.table-proxy > tbody').append(`
                 <tr>
                    <td>
                        <input type="checkbox" class="form-control proxy-id" value="${dataJson.addedProxy.id}">
                    </td>
                    <td>
                        <input type="text" class="form-control proxy-url" value="${newProxyUrl}">
                    </td>
                    <td>
                        <input type="number" class="form-control proxy-rotation-value" value="${newRotationValue}">
                    </td>
                    <td></td>
                </tr>
            `);

            showSuccess('Add proxy successfully!').then(function () {
                location.reload();
            });
        });
    }

    function saveProxies() {
        var proxies = [];
        var hasError = false;
        var rotationMethod = $('input[name="rotationMethod"]:checked').val();
        var rotationMethodName = rotationMethod === 'by_time' ? 'Time' : 'Amount';
        $('.table-proxy tr.proxy').each(function () {
            var $row = $(this);
            var $urlInput = $row.find('.proxy-url');
            var sanitizedUrl = csSanitizeShieldDomain($urlInput.val());
            if (sanitizedUrl) $urlInput.val(sanitizedUrl);
            var proxy = {
                id: $row.find('.proxy-id').val(),
                url: sanitizedUrl,
                rotationValue: $row.find('.proxy-rotation-value').val(),
            };
            if (!proxy.url || !proxy.rotationValue.trim()) {
                showError('Please fill in all required field!');
                hasError = true;
                return;
            }
            if (proxy.rotationValue <= 0) {
                showError(rotationMethodName + ' must be greater than 0!');
                hasError = true;
                return;
            }
            proxies.push(proxy);
        })
        if (hasError) {
            return;
        }

        csPreCheckProxiesHealth(proxies, 'paypal').then(function (verdict) {
            if (verdict.unreachable.length > 0) {
                showError("Can't connect to shield domain(s):\n" + verdict.unreachable.join('\n'));
                return;
            }
            var commit = function () {
                var data = {
                    'action': 'mecom_gateway_paypal_action',
                    'command': 'saveProxies',
                    'rotationMethod': rotationMethod,
                    'proxies': proxies
                };
                jQuery.post(cs_ajax_object.ajax_url, data, function (response) {
                    showSuccess('Save proxies success!').then(function () {
                        location.reload();
                    });
                });
            };
            if (verdict.emptyLiveKey.length === 0) { commit(); return; }
            Swal.fire({
                title: 'Warning',
                html: 'These shield domain(s) have no live key configured:<br><br>'
                    + verdict.emptyLiveKey.map(function (d) { return '<code>' + d + '</code>'; }).join('<br>')
                    + '<br><br>They will only work in sandbox mode. Save anyway?',
                type: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Save anyway',
                cancelButtonText: 'Cancel'
            }).then(function (result) {
                if (result.value) commit();
            });
        });
    }

    function csPreCheckProxiesHealth(proxies, type) {
        var unique = Array.from(new Set(proxies.map(function (p) { return p.url; })));
        Swal.fire({ title: 'Checking shield(s)...', text: 'Please wait', allowOutsideClick: false, allowEscapeKey: false, showConfirmButton: false });
        Swal.showLoading();
        return Promise.all(unique.map(function (url) {
            return csCheckShieldHealth(url, type).then(function (status) {
                return { url: url, status: status };
            });
        })).then(function (results) {
            Swal.close();
            return {
                unreachable: results.filter(function (r) { return r.status === 'UNREACHABLE'; }).map(function (r) { return r.url; }),
                emptyLiveKey: results.filter(function (r) { return r.status === 'EMPTY_LIVE_KEY'; }).map(function (r) { return r.url; })
            };
        });
    }

    function forceActive() {
        var selectedIds = $('.table-proxy').find('.proxy-id:checked').map(function(){
            return this.value;
        }).get();

        if (selectedIds.length !== 1 ) {
            showError('Please select one proxy to activate!');
            return;
        }
        if ($('.table-proxy').find('.proxy-id:checked').closest('tr').hasClass('activated-proxy')) {
            showError('Already activated proxy');
            return;
        }
        showConfirm("The new proxy will be activated and use as main Payment method!").then(function (result) {
            if (!result.value) {
                return;
            }
            var rotationMethod = $('input[name="rotationMethod"]:checked').val();
            var data = {
                'action': 'mecom_gateway_paypal_action',
                'command': 'activateProxy',
                'rotationMethod': rotationMethod,
                'proxyID': selectedIds[0]
            };
            jQuery.post(cs_ajax_object.ajax_url, data, function (response) {
                var responseJson = JSON.parse(response);
                if (responseJson.success) {
                    $('tr.activated-proxy').removeClass('activated-proxy');
                    $('.table-proxy').find('.proxy-id:checked').closest('tr').addClass('activated-proxy');
                }
            });
        });

    }

    function moveToUnused() {
        var selectedIds = $('.table-proxy').find('.proxy-id:checked').map(function(){
            return this.value;
        }).get();

        if (selectedIds.length <= 0 ) {
            showError('Please select at least one proxy!');
            return;
        }

        showConfirm("Selected proxy will be moved to Unused list!").then(function (result) {
            if (!result.value) {
                return;
            }
            var rotationMethod = $('input[name="rotationMethod"]:checked').val();
            var data = {
                'action': 'mecom_gateway_paypal_action',
                'command': 'moveToUnusedProxies',
                'rotationMethod': rotationMethod,
                'proxyIds': selectedIds
            };
            jQuery.post(cs_ajax_object.ajax_url, data, function (response) {
                var responseJson = JSON.parse(response);
                if (responseJson.success) {
                    $('.table-proxy').find('.proxy-id:checked').each(function () {
                       $(this).closest('tr').appendTo('.table-unused > tbody');
                    });
                    location.reload();
                } else {
                    showError(responseJson.error).then(function () {
                        location.reload();
                    });
                }
            });
        });
    }



    $('input[type=radio][name=rotationMethod]').change(function () {
        var rotationMethod = this.value;
        var methodName = rotationMethod === 'by_time' ? 'time' : 'amount';
        showConfirm(`Proxy will be rotated by <b>${methodName}</b>.`).then((result) => {
            if (result.value) {
                var data = {
                    'action': 'mecom_gateway_paypal_action',
                    'command': 'changeRotationMethod',
                    'rotationMethod': rotationMethod
                };
                jQuery.post(cs_ajax_object.ajax_url, data, function (response) {
                    var responseJson = JSON.parse(response);
                    if (responseJson.success === true) {
                        // toggleRotationMethod(rotationMethod);
                        // replaceProxyList(responseJson.proxies);
                        return showSuccess(`Rotation method changed to <b>${methodName}</b>!`).then(function () {
                            location.reload();
                        })
                    } else {
                        showError('Failed to change rotation method. Please try again!');
                        // Revert previous value
                        revertPreviousRotationMethod(rotationMethod);
                    }
                });

            } else {
                // Revert previous value
                revertPreviousRotationMethod(rotationMethod);
            }
        });
    });

    function deleteProxy() {

        var selectedIds = $('.table-unused').find('.proxy-id:checked').map(function(){
            return this.value;
        }).get();

        if (selectedIds.length <= 0 ) {
            showError('Please select at least one proxy!');
            return;
        }

        showConfirm('Selected proxy will be deleted!').then(function (result) {
            if (!result.value) {
                return;
            }
            var data = {
                'action': 'mecom_gateway_paypal_action',
                'command': 'deleteProxy',
                'deleteProxyIds': selectedIds
            };
            jQuery.post(cs_ajax_object.ajax_url, data, function (response) {
                var responseJson = JSON.parse(response);
                if (responseJson.success === true) {
                    $('.table-unused').find('.proxy-id:checked').closest('tr').remove();
                    showSuccess('Selected proxies has been deleted successfully!');
                } else {
                    showError('Failed to delete selected proxies!');
                }
            });
        })

    }

    function moveBackProxy() {
        var selectedIds = $('.table-unused').find('.proxy-id:checked').map(function(){
            return this.value;
        }).get();

        if (selectedIds.length <= 0 ) {
            showError('Please select at least one proxy!');
            return;
        }

        var data = {
            'action': 'mecom_gateway_paypal_action',
            'command': 'moveBackProxies',
            'moveBackProxyIds': selectedIds
        };
        jQuery.post(cs_ajax_object.ajax_url, data, function (response) {
            var responseJson = JSON.parse(response);
            if (responseJson.success === true) {
                $('.table-unused').find('.proxy-id:checked').closest('tr').appendTo('.table-proxy > tbody');
                showSuccess('Selected proxies has been moved back successfully!').then(function (){
                    location.reload();
                });
            } else {
                showError('Failed to move back selected proxies!');
            }

        });

    }

    function toggleSyncTrackingLoading(isOn) {
        $('#sync-spinner').css("display", isOn ? "inline-block" : "none");
        $('#sync-tracking-info-btn').attr("disabled", isOn);
    }

    function syncTrackingInfo() {

        var syncCount = $('#sync-count').val();
        if (parseInt(syncCount) === 0) {
            showError("Don't have unsynced orders");
            return;
        }
        toggleSyncTrackingLoading(true);

        var data = {
            'action': 'mecom_gateway_paypal_action',
            'command': 'syncTrackingInfo',
        };
        jQuery.ajaxSetup({timeout: 100000});

        function doSyncBatch(onDone) {
            jQuery.post(cs_ajax_object.ajax_url, data, function (response) {
                var responseJson = JSON.parse(response);
                if (responseJson.success) {
                    if (responseJson.count > 0) {
                        doSyncBatch(onDone);
                    } else {
                        onDone(true);
                    }
                } else {
                    onDone(false, responseJson.error);
                }
            }).fail(function () {
                onDone(false, 'Error when sync tracking info. Please try again after!');
            });
        }

        doSyncBatch(function (isSuccess, errorMessage) {
            toggleSyncTrackingLoading(false);
            if (isSuccess) {
                showSuccess('Sync tracking info successfully!').then(function () {
                    location.reload();
                });
            } else {
                showError(errorMessage);
            }
        });
    }
    
    function saveEndpointSettings() {
        var data = {
            'action': 'mecom_gateway_paypal_action',
            'command': 'saveEndpointSettings',
            'endpointToken': $('input[name="endpointToken"]').val(),
            'endpointSecret': $('input[name="endpointSecret"]').val(),
        };
        jQuery.post(cs_ajax_object.ajax_url, data, function (response) {
            var responseJson = JSON.parse(response);
            if (responseJson.success === true) {
                showSuccess('Save endpoint settings successfully!');
            } else {
                showError('Save endpoint settings failed!');
            }
        });
    }

    $(document).on('click', '#btn-add-proxy', function () {
        addProxy();
    });

    $(document).on('click', '#btn-save', function () {
        saveProxies();
    });

    $(document).on('click', '#btn-force-active', function () {
        forceActive();
    });

    $(document).on('click', '#btn-move-unused', function () {
        moveToUnused();
    });

    $(document).on('click', '#btn-delete', function () {
        deleteProxy();
    });

    $(document).on('click', '#btn-move-back', function () {
        moveBackProxy();
    });

    $(document).on('click', '#sync-tracking-info-btn', function () {
        syncTrackingInfo();
    });
    
    function csValidateEndpointToken(callback) {
        jQuery.post(cs_ajax_object.ajax_url, {
            'action': 'mecom_gateway_paypal_action',
            'command': 'validateEndpointToken',
            'endpointToken': $('input[name="endpointToken"]').val(),
            'endpointSecret': $('input[name="endpointSecret"]').val(),
        }, function (response) {
            var result;
            try { result = JSON.parse(response); } catch (e) { result = { status: 'ok' }; }
            callback(result);
        });
    }

    function csRenderEndpointTokenWarning(result) {
        var $banner = $('#cs-ep-token-warning');
        if (result && (result.status === 'warning' || result.status === 'error') && result.message) {
            $banner.text(result.message).show();
        } else {
            $banner.hide().text('');
        }
    }

    $(document).on('click', '#btn-save-endpoint-settings', function () {
        csValidateEndpointToken(function (result) {
            csRenderEndpointTokenWarning(result);
            if (result && (result.status === 'warning' || result.status === 'error') && result.message) {
                showConfirm(result.message + '\n\nSave anyway?').then(function (r) {
                    if (r.value) { saveEndpointSettings(); }
                });
            } else {
                saveEndpointSettings();
            }
        });
    });
    
    $(document).on('click', '#btn-save-endpoint-cancel', function () {
        window.location.reload();
    });
    var $currentConnectionMode = $('input[type=radio][name=connectionMode]:checked').val();
    if ($currentConnectionMode == 'endpoint_token') {
         jQuery.post(cs_ajax_object.ajax_url, {
            'action': 'mecom_gateway_paypal_action',
            'command': 'getEndpointRemainingAmount'
        }, function (response) {
            try {
                var responseJson = JSON.parse(response);
                if (responseJson.value) {
                    $('#paypal-ep-amount-remain').html(responseJson.value)
                }
            } catch (e) {}
        });
        csValidateEndpointToken(csRenderEndpointTokenWarning);
    }
    $('input[type=radio][name=connectionMode]').click(function(e) {
        e.preventDefault();
        var radioEl = $(this)
        var connectionMode = this.value;
        if ($currentConnectionMode === connectionMode) {
            return false;
        }
        if (connectionMode === 'shield_domains') {
            var msgConfirmDialog = "Connection mode shield domains will be activated!";
        } else {
            var msgConfirmDialog = "Connection mode endpoint token will be activated!";
        }
        return showConfirm(msgConfirmDialog).then(function (result) {
            if (!result.value) {
                return false;
            }
            var data = {
                'action': 'mecom_gateway_paypal_action',
                'command': 'changeConnectionMode',
                'connectionMode': connectionMode,
            };
            jQuery.post(cs_ajax_object.ajax_url, data, function (response) {
                var responseJson = JSON.parse(response);
                if (responseJson.success) {
                    radioEl.prop('checked', true);
                    $currentConnectionMode = connectionMode;
                    if (connectionMode === 'shield_domains') {
                        $('#connection_mode_shield_domains_area').show();
                        $('#connection_mode_endpoint_token_area').hide();
                        csRenderEndpointTokenWarning(null);
                        showSuccess("Connection mode shield domains activated!");
                    } else {
                        $('#connection_mode_endpoint_token_area').show();
                        $('#connection_mode_shield_domains_area').hide();
                        showSuccess("Connection mode endpoint token activated!");
                        csValidateEndpointToken(csRenderEndpointTokenWarning);
                        jQuery.post(cs_ajax_object.ajax_url, {
                            'action': 'mecom_gateway_paypal_action',
                            'command': 'getEndpointRemainingAmount'
                        }, function (response) {
                            try {
                                var responseJson = JSON.parse(response);
                                if (responseJson.value) {
                                    $('#paypal-ep-amount-remain').html(responseJson.value)
                                }
                            } catch (e) {}
                        });
                    }
                } else {
                    showError('Change connection mode failed!');
                }
            });
        });
    });
})
