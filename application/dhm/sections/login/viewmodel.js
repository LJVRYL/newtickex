define(['knockout'], function(ko) {

    ko.bindingHandlers.autoTrigger = {
        init: function(element, valueAccessor) {
            var func = valueAccessor();
            func();
        }
    };

    var LoginVM = function() {
        'use strict';
        this.inprocess = ko.observable(0);
        this.user = ko.observable('');
        this.password = ko.observable('');
        this.errors = ko.observable();
        this.captchaType = null;
        this.captchaWidgetId = null;
        this.captchaResponse = null;
        mediator.installTo(this);
    };

    LoginVM.prototype.initCaptcha = function() {
        var self = this;
        var container = document.querySelector("#recaptcha-login");
    
        if (!container) return;
    
        if (typeof turnstile !== "undefined") {
            FerozoDhm.loginVM().captchaType = "turnstile";
            FerozoDhm.loginVM().captchaWidgetId = turnstile.render("#recaptcha-login", {
                sitekey: recaptcha_key,
                theme: 'light',
                size: 'normal'
            });
        } else if (typeof grecaptcha !== "undefined") {
            FerozoDhm.loginVM().captchaType = "recaptcha";
            FerozoDhm.loginVM().captchaWidgetId = grecaptcha.render("recaptcha-login", {
                sitekey: recaptcha_key,
                callback: function(token) {
                    FerozoDhm.loginVM().captchaResponse = token;
                }
            });
        } else {
            setTimeout(function() { self.initCaptcha(); }, 500);
        }
    };       

    LoginVM.prototype.getCaptchaResponse = function() {
        var self = this;
        if (FerozoDhm.loginVM().captchaType === "turnstile") {
            return turnstile.getResponse(FerozoDhm.loginVM().captchaWidgetId);
        } else if (FerozoDhm.loginVM().captchaType === "recaptcha") {
            return FerozoDhm.loginVM().captchaResponse || '';
        } else {
            return $('input[name="cf-turnstile-response"]').val() || '';
        }
    };

    LoginVM.prototype.resetCaptcha = function() {
        var self = this;
        if (FerozoDhm.loginVM().captchaType === "turnstile") {
            turnstile.reset(FerozoDhm.loginVM().captchaWidgetId);
        } else if (FerozoDhm.loginVM().captchaType === "recaptcha") {
            grecaptcha.reset(FerozoDhm.loginVM().captchaWidgetId);
        }
    };

    LoginVM.prototype.removeCaptcha = function() {
        var self = this;
        if (FerozoDhm.loginVM().captchaType === "turnstile") {
            turnstile.remove(FerozoDhm.loginVM().captchaWidgetId);
        }
    };

    LoginVM.prototype.login = function() {
        var self = this;

        $("#form-login").find("input").change();

        var token = self.getCaptchaResponse();
        
        var theData = {
            "params": {
                "user": self.user(),
                "password": self.password(),
                "cf-turnstile-response": typeof turnstile !== "undefined" ? token : '',
                "g-recaptcha-response": typeof grecaptcha !== "undefined" ? token : '',
            }
        };

        self.inprocess(1);
        self.errors('');

        $.postJSON('/dhm/loginajax', theData, function(response) {
            if (response.error && response.error.data.inputException) {
                $.each(response.error.data.inputException, function() {
                    self.errors(this.errorDesc);
                });
                self.resetCaptcha();
            } else {
                self.password('');
                FerozoUtils.security.requestToken();
                FerozoDhm.connection.needlogin(0);
                FerozoDhm.getActiveSectionVM().init();
                FerozoDhm.loginVM().removeCaptcha();
            }
        }).always(function() {
            self.inprocess(0);
        });
    };

    return LoginVM;
});
