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
        this.boundInitCaptcha = this.initCaptcha.bind(this);
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
            FerozoHosting.loginVM().captchaType = "turnstile";
            FerozoHosting.loginVM().captchaWidgetId = turnstile.render("#recaptcha-login", {
                sitekey: recaptcha_key,
                theme: 'light',
                size: 'normal'
            });
        } else if (typeof grecaptcha !== "undefined") {
            FerozoHosting.loginVM().captchaType = "recaptcha";
            FerozoHosting.loginVM().captchaWidgetId = grecaptcha.render("recaptcha-login", {
                sitekey: recaptcha_key,
                callback: function(token) {
                    FerozoHosting.loginVM().captchaResponse = token;
                }
            });
        } else {
            setTimeout(function() { self.initCaptcha(); }, 500);
        }
    };
    
    LoginVM.prototype.init = function() {
    };

    LoginVM.prototype.getCaptchaResponse = function() {
        var self = this;
        if (FerozoHosting.loginVM().captchaType === "turnstile") {
            return turnstile.getResponse(FerozoHosting.loginVM().captchaWidgetId);
        } else if (FerozoHosting.loginVM().captchaType === "recaptcha") {
            return FerozoHosting.loginVM().captchaResponse || '';
        } else {
            return $('input[name="cf-turnstile-response"]').val() || '';
        }
    };

    LoginVM.prototype.resetCaptcha = function() {
        var self = this;
        if (FerozoHosting.loginVM().captchaType === "turnstile") {
            turnstile.reset(FerozoHosting.loginVM().captchaWidgetId);
        } else if (FerozoHosting.loginVM().captchaType === "recaptcha") {
            grecaptcha.reset(FerozoHosting.loginVM().captchaWidgetId);
        }
    };

    LoginVM.prototype.removeCaptcha = function() {
        var self = this;
        if (FerozoHosting.loginVM().captchaType === "turnstile") {
            turnstile.remove(FerozoHosting.loginVM().captchaWidgetId);
        }
    };

    LoginVM.prototype.login = function() {
        var self = this;
        
        if (typeof turnstile !== "undefined") {
            var token = turnstile.getResponse();
        } else {
            var token = $('input[name=cf-turnstile-response]').val();
        }

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

        $.postJSON('/common/loginajax', theData, function(response) {
            if (response.error && response.error.data.inputException) {
                $.each(response.error.data.inputException, function() {
                    self.errors(this.errorDesc);
                });
                self.resetCaptcha();
            } else {
                self.password('');
                FerozoUtils.security.requestToken();
                FerozoHosting.connection.needlogin(0);
                FerozoHosting.getActiveSectionVM().init();
                FerozoHosting.loginVM().removeCaptcha();
            }
        }).always(function() {
            self.inprocess(0);
        });
    };

    return LoginVM;
});
