/*! @proximafortedev/payxy 1.0.10 | ISC License | Official Payxy browser checkout overlay, bundled unmodified from npm (dist/payxy.umd.js). */
(function (global, factory) {
    typeof exports === 'object' && typeof module !== 'undefined' ? factory(exports) :
    typeof define === 'function' && define.amd ? define(['exports'], factory) :
    (global = typeof globalThis !== 'undefined' ? globalThis : global || self, factory(global.Payxy = {}));
})(this, (function (exports) { 'use strict';

    /******************************************************************************
    Copyright (c) Microsoft Corporation.

    Permission to use, copy, modify, and/or distribute this software for any
    purpose with or without fee is hereby granted.

    THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES WITH
    REGARD TO THIS SOFTWARE INCLUDING ALL IMPLIED WARRANTIES OF MERCHANTABILITY
    AND FITNESS. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR ANY SPECIAL, DIRECT,
    INDIRECT, OR CONSEQUENTIAL DAMAGES OR ANY DAMAGES WHATSOEVER RESULTING FROM
    LOSS OF USE, DATA OR PROFITS, WHETHER IN AN ACTION OF CONTRACT, NEGLIGENCE OR
    OTHER TORTIOUS ACTION, ARISING OUT OF OR IN CONNECTION WITH THE USE OR
    PERFORMANCE OF THIS SOFTWARE.
    ***************************************************************************** */
    /* global Reflect, Promise, SuppressedError, Symbol, Iterator */


    var __assign = function() {
        __assign = Object.assign || function __assign(t) {
            for (var s, i = 1, n = arguments.length; i < n; i++) {
                s = arguments[i];
                for (var p in s) if (Object.prototype.hasOwnProperty.call(s, p)) t[p] = s[p];
            }
            return t;
        };
        return __assign.apply(this, arguments);
    };

    typeof SuppressedError === "function" ? SuppressedError : function (error, suppressed, message) {
        var e = new Error(message);
        return e.name = "SuppressedError", e.error = error, e.suppressed = suppressed, e;
    };

    var DEFAULT_ENVIRONMENT_URLS = {
        development: "http://localhost:5006",
        staging: "https://checkout.payxy.ng",
        production: "https://checkout.payxy.africa",
    };
    var Payxy = /** @class */ (function () {
        function Payxy(options) {
            var _this = this;
            this.overlay = null;
            this.iframe = null;
            this.handleMessage = function (event) {
                var _a, _b, _c, _d, _e, _f, _g, _h;
                if (event.origin !== _this.origin)
                    return;
                var _j = event.data, status = _j.status, data = _j.data, action = _j.action;
                if (action === "close-transaction") {
                    (_b = (_a = _this.options).onClose) === null || _b === void 0 ? void 0 : _b.call(_a);
                    _this.close();
                    return;
                }
                if (status === "SUCCESS") {
                    (_d = (_c = _this.options).onSuccess) === null || _d === void 0 ? void 0 : _d.call(_c, data);
                    _this.close();
                }
                else if (status === "FAILED") {
                    (_f = (_e = _this.options).onError) === null || _f === void 0 ? void 0 : _f.call(_e, data);
                    _this.close();
                }
                else if (status === "CLOSED") {
                    (_h = (_g = _this.options).onClose) === null || _h === void 0 ? void 0 : _h.call(_g);
                    _this.close();
                }
            };
            this.options = options;
            this.origin = this.resolveOrigin();
            this.injectStyles();
        }
        Payxy.prototype.resolveOrigin = function () {
            var _a;
            var mergedEnvironmentUrls = this.normalizeAndValidateEnvironmentUrls(__assign(__assign({}, DEFAULT_ENVIRONMENT_URLS), ((_a = this.options.environmentUrls) !== null && _a !== void 0 ? _a : {})));
            if (this.options.origin) {
                return this.normalizeAndValidateOrigin(this.options.origin, "origin");
            }
            if (this.options.environment) {
                return this.normalizeOrigin(mergedEnvironmentUrls[this.options.environment]);
            }
            var hostname = window.location.hostname;
            var inferredEnvironment = hostname === "localhost" || hostname === "127.0.0.1"
                ? "development"
                : "production";
            return this.normalizeOrigin(mergedEnvironmentUrls[inferredEnvironment]);
        };
        Payxy.prototype.normalizeAndValidateEnvironmentUrls = function (urls) {
            return {
                development: this.normalizeAndValidateOrigin(urls.development, "environmentUrls.development"),
                staging: this.normalizeAndValidateOrigin(urls.staging, "environmentUrls.staging"),
                production: this.normalizeAndValidateOrigin(urls.production, "environmentUrls.production"),
            };
        };
        Payxy.prototype.normalizeAndValidateOrigin = function (url, source) {
            var normalized = this.normalizeOrigin(url.trim());
            var parsedUrl;
            try {
                parsedUrl = new URL(normalized);
            }
            catch (_a) {
                throw new Error("Invalid ".concat(source, " URL: \"").concat(url, "\". Expected a full URL such as https://example.com"));
            }
            if (parsedUrl.protocol !== "http:" && parsedUrl.protocol !== "https:") {
                throw new Error("Invalid ".concat(source, " URL protocol: \"").concat(parsedUrl.protocol, "\". Only http and https are supported"));
            }
            return normalized;
        };
        Payxy.prototype.normalizeOrigin = function (url) {
            return url.replace(/\/+$/, "");
        };
        Payxy.prototype.injectStyles = function () {
            var existingStyle = document.getElementById("payxy-style");
            if (existingStyle)
                return;
            var style = document.createElement("style");
            style.id = "payxy-style";
            style.textContent = "\n      .payxy-iframe {\n        position: fixed;\n        top: 0;\n        left: 0;\n        width: 100%;\n        height: 100svh;\n        border: none;\n        z-index: 999999;\n      }\n      .payxy-overlay {\n        position: fixed;\n        top: 0;\n        left: 0;\n        width: 100%;\n        height: 100%;\n        background: rgba(0, 0, 0, 0.6);\n        z-index: 999999;\n      }\n      .payxy-close-btn {\n        position: absolute;\n        top: 20px;\n        right: 30px;\n        font-size: 30px;\n        background: none;\n        color: #fff;\n        border: none;\n        cursor: pointer;\n        z-index: 1000000;\n      }\n    ";
            document.head.appendChild(style);
        };
        Payxy.prototype.init = function () {
            var _this = this;
            if (this.overlay)
                return;
            var overlay = document.createElement("div");
            overlay.className = "payxy-overlay";
            var iframe = document.createElement("iframe");
            iframe.className = "payxy-iframe";
            iframe.src = "".concat(this.origin, "/").concat(this.options.accessCode);
            iframe.setAttribute("frameborder", "0");
            var closeBtn = document.createElement("button");
            closeBtn.className = "payxy-close-btn";
            closeBtn.innerHTML = "×";
            closeBtn.onclick = function () { return _this.close(); };
            overlay.appendChild(closeBtn);
            overlay.appendChild(iframe);
            document.body.appendChild(overlay);
            this.overlay = overlay;
            this.iframe = iframe;
            window.addEventListener("message", this.handleMessage);
            iframe.onload = function () {
                var _a;
                // Send only serializable data — no functions
                (_a = iframe.contentWindow) === null || _a === void 0 ? void 0 : _a.postMessage({
                    action: "init",
                    data: {
                        accessCode: _this.options.accessCode,
                    },
                }, _this.origin);
            };
        };
        Payxy.prototype.close = function () {
            if (this.overlay) {
                document.body.removeChild(this.overlay);
                this.overlay = null;
                this.iframe = null;
                window.removeEventListener("message", this.handleMessage);
            }
        };
        return Payxy;
    }());

    exports.Payxy = Payxy;

}));
