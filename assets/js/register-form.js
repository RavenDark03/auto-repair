(function () {
    'use strict';

    var PSGC_BASE = 'https://psgc.gitlab.io/api';
    var NCR_REGION_CODE = '130000000';

    function $(id) {
        return document.getElementById(id);
    }

    function readOldInput() {
        var el = $('register-old-json');
        if (!el || !el.textContent) {
            return {};
        }
        try {
            return JSON.parse(el.textContent);
        } catch (e) {
            return {};
        }
    }

    function fetchJson(url) {
        return fetch(url, { headers: { Accept: 'application/json' } }).then(function (res) {
            if (!res.ok) {
                throw new Error('Address lookup failed (' + res.status + ')');
            }
            return res.json();
        });
    }

    function setFieldError(id, message) {
        var el = $(id);
        if (!el) {
            return;
        }
        el.textContent = message || '';
        el.hidden = !message;
    }

    function clearFieldErrors() {
        ['err-email', 'err-phone', 'err-address_line1', 'err-address_line2', 'err-address_region_code', 'err-address_city_code', 'err-address_brgy_code'].forEach(function (id) {
            setFieldError(id, '');
        });
    }

    function fillSelect(select, items, valueKey, labelKey, placeholder) {
        select.innerHTML = '';
        var opt0 = document.createElement('option');
        opt0.value = '';
        opt0.textContent = placeholder;
        select.appendChild(opt0);
        items.forEach(function (item) {
            var opt = document.createElement('option');
            opt.value = String(item[valueKey]);
            opt.textContent = item[labelKey];
            opt.dataset.name = item[labelKey];
            select.appendChild(opt);
        });
        select.disabled = items.length === 0;
    }

    function syncNameHidden(selectId, hiddenId) {
        var sel = $(selectId);
        var hid = $(hiddenId);
        if (!sel || !hid) {
            return;
        }
        var opt = sel.options[sel.selectedIndex];
        hid.value = opt && opt.dataset.name ? opt.dataset.name : '';
    }

    function populateRegions(regionSelect) {
        return fetchJson(PSGC_BASE + '/regions/').then(function (regions) {
            regions.sort(function (a, b) {
                return a.name.localeCompare(b.name);
            });
            fillSelect(regionSelect, regions, 'code', 'name', 'Select region');
        });
    }

    function loadProvinces(regionCode, provinceSelect) {
        return fetchJson(PSGC_BASE + '/regions/' + regionCode + '/provinces/').then(function (provinces) {
            provinces.sort(function (a, b) {
                return a.name.localeCompare(b.name);
            });
            fillSelect(provinceSelect, provinces, 'code', 'name', 'Select province');
        });
    }

    function loadCitiesNcr(citySelect) {
        return fetchJson(PSGC_BASE + '/regions/' + NCR_REGION_CODE + '/cities-municipalities/').then(function (cities) {
            cities.sort(function (a, b) {
                return a.name.localeCompare(b.name);
            });
            fillSelect(citySelect, cities, 'code', 'name', 'Select city / municipality');
        });
    }

    function loadCitiesForProvince(provinceCode, citySelect) {
        return fetchJson(PSGC_BASE + '/provinces/' + provinceCode + '/cities-municipalities/').then(function (cities) {
            cities.sort(function (a, b) {
                return a.name.localeCompare(b.name);
            });
            fillSelect(citySelect, cities, 'code', 'name', 'Select city / municipality');
        });
    }

    function loadBarangays(cityCode, brgySelect) {
        return fetchJson(PSGC_BASE + '/cities-municipalities/' + cityCode + '/barangays/').then(function (list) {
            list.sort(function (a, b) {
                return a.name.localeCompare(b.name);
            });
            fillSelect(brgySelect, list, 'code', 'name', 'Select barangay');
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var form = $('registration-form');
        if (!form) {
            return;
        }

        var regionSel = $('address_region_code');
        var provinceWrap = $('address_province_wrap');
        var provinceSel = $('address_province_code');
        var citySel = $('address_city_code');
        var brgySel = $('address_brgy_code');
        var phoneSuffix = $('phone_suffix');
        var phoneFull = $('phone');
        var emailInput = $('email');

        var old = readOldInput();
        var restore = {
            region: old.address_region_code || '',
            province: old.address_province_code || '',
            city: old.address_city_code || '',
            brgy: old.address_brgy_code || ''
        };

        function applyPhoneFromOld() {
            if (!phoneSuffix || !phoneFull) {
                return;
            }
            var p = String(old.phone || '');
            if (p.indexOf('+639') === 0 && p.length >= 13) {
                phoneSuffix.value = p.slice(4).replace(/\D/g, '').slice(0, 9);
                phoneFull.value = '+639' + phoneSuffix.value;
            }
        }

        applyPhoneFromOld();

        if (phoneSuffix && phoneFull) {
            phoneSuffix.addEventListener('input', function () {
                phoneSuffix.value = phoneSuffix.value.replace(/\D/g, '').slice(0, 9);
                phoneFull.value = '+639' + phoneSuffix.value;
            });
        }

        if (emailInput) {
            emailInput.addEventListener('blur', function () {
                if (emailInput.validity.typeMismatch) {
                    setFieldError('err-email', 'Enter a valid email address.');
                } else {
                    setFieldError('err-email', '');
                }
            });
            emailInput.addEventListener('input', function () {
                if (emailInput.value.trim() === '') {
                    setFieldError('err-email', '');
                }
            });
        }

        function resetCityBrgy() {
            fillSelect(citySel, [], 'code', 'name', 'Select city / municipality');
            fillSelect(brgySel, [], 'code', 'name', 'Select barangay');
            citySel.disabled = true;
            brgySel.disabled = true;
            syncNameHidden('address_city_code', 'address_city_name');
            syncNameHidden('address_brgy_code', 'address_brgy_name');
        }

        function onRegionChange(fromUser) {
            if (fromUser) {
                clearFieldErrors();
            }
            var code = regionSel.value;
            $('address_region_name').value = regionSel.options[regionSel.selectedIndex]
                ? regionSel.options[regionSel.selectedIndex].dataset.name || ''
                : '';

            provinceSel.innerHTML = '';
            provinceSel.disabled = true;
            resetCityBrgy();

            if (!code) {
                provinceWrap.hidden = true;
                provinceSel.removeAttribute('required');
                provinceSel.innerHTML = '<option value="">Select province</option>';
                provinceSel.disabled = true;
                return Promise.resolve();
            }

            if (code === NCR_REGION_CODE) {
                provinceWrap.hidden = true;
                provinceSel.removeAttribute('required');
                return loadCitiesNcr(citySel).then(function () {
                    if (restore.city) {
                        citySel.value = restore.city;
                        return onCityChange(false);
                    }
                });
            }

            provinceWrap.hidden = false;
            provinceSel.setAttribute('required', 'required');
            return loadProvinces(code, provinceSel).then(function () {
                if (restore.province) {
                    provinceSel.value = restore.province;
                    return onProvinceChange(false);
                }
            });
        }

        function onProvinceChange(fromUser) {
            if (fromUser) {
                clearFieldErrors();
            }
            $('address_province_name').value = provinceSel.options[provinceSel.selectedIndex]
                ? provinceSel.options[provinceSel.selectedIndex].dataset.name || ''
                : '';

            resetCityBrgy();
            var pcode = provinceSel.value;
            if (!pcode) {
                return Promise.resolve();
            }
            return loadCitiesForProvince(pcode, citySel).then(function () {
                if (old.address_city_code) {
                    citySel.value = old.address_city_code;
                    return onCityChange(false);
                }
            });
        }

        function onCityChange(fromUser) {
            if (fromUser) {
                clearFieldErrors();
            }
            syncNameHidden('address_city_code', 'address_city_name');
            fillSelect(brgySel, [], 'code', 'name', 'Select barangay');
            brgySel.disabled = true;
            var ccode = citySel.value;
            if (!ccode) {
                return Promise.resolve();
            }
            return loadBarangays(ccode, brgySel).then(function () {
                if (restore.brgy) {
                    brgySel.value = restore.brgy;
                    syncNameHidden('address_brgy_code', 'address_brgy_name');
                }
            });
        }

        regionSel.addEventListener('change', function () {
            onRegionChange(true).catch(function () {
                setFieldError('err-address_region_code', 'Could not load cities. Try again.');
            });
        });
        provinceSel.addEventListener('change', function () {
            onProvinceChange(true).catch(function () {
                setFieldError('err-address_city_code', 'Could not load cities. Try again.');
            });
        });
        citySel.addEventListener('change', function () {
            onCityChange(true).catch(function () {
                setFieldError('err-address_brgy_code', 'Could not load barangays. Try again.');
            });
        });
        brgySel.addEventListener('change', function () {
            syncNameHidden('address_brgy_code', 'address_brgy_name');
        });

        populateRegions(regionSel)
            .then(function () {
                if (restore.region) {
                    regionSel.value = restore.region;
                    return onRegionChange(false);
                }
            })
            .catch(function () {
                setFieldError('err-address_region_code', 'Could not load regions. Check your connection.');
            });

        form.addEventListener('submit', function (e) {
            clearFieldErrors();
            var ok = true;

            if (emailInput && emailInput.value.trim() !== '' && !emailInput.checkValidity()) {
                setFieldError('err-email', 'Enter a valid email address.');
                ok = false;
            }

            if (phoneSuffix && phoneFull) {
                phoneFull.value = '+639' + phoneSuffix.value.replace(/\D/g, '').slice(0, 9);
                if (!/^\+639\d{9}$/.test(phoneFull.value)) {
                    setFieldError('err-phone', 'Enter exactly 9 digits after +639 for a Philippine mobile number.');
                    ok = false;
                }
            }

            if (!regionSel.value) {
                setFieldError('err-address_region_code', 'Select your region.');
                ok = false;
            }
            if (regionSel.value && regionSel.value !== NCR_REGION_CODE && !provinceSel.value) {
                setFieldError('err-address_city_code', 'Select your province.');
                ok = false;
            }
            if (!citySel.value) {
                setFieldError('err-address_city_code', 'Select your city or municipality.');
                ok = false;
            }
            if (!brgySel.value) {
                setFieldError('err-address_brgy_code', 'Select your barangay.');
                ok = false;
            }

            var pw = $('password');
            var pwc = $('password_confirm');
            if (pw && pwc && pw.value !== pwc.value) {
                ok = false;
                alert('Passwords do not match.');
            }

            if (!ok) {
                e.preventDefault();
            }
        });
    });
})();
