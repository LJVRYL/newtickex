define(['knockout', 'user', 'cookies'], function(ko, User, Cookies) {
    var ProfileVM = function() {

        this.user = ko.observable(new User());
        this.temp = ko.observable(new User());
        this.inprocess =  ko.observable(0);

        this.showPassword = ko.observable();
        this.showModalMaxHosting = ko.observable(false);
        this.numTotalHosting = ko.observable();
        this.numMaxHosting = ko.observable();

        this.secret2fa = ko.observable('');
        this.ImageBase64 = ko.observable('');
        this.authCode = ko.observable('');
        this.authFlag = ko.observable(false);
    };

    ProfileVM.prototype.isDhm = function() {
        return this.user() && !!this.user().idDhm();
    };

    ProfileVM.prototype.getServerType = function() {
        return this.user() && this.user().Server.Type();
    };

    ProfileVM.prototype.openModalEdit = function() {
        var self = this;

        if (! self.user().UserName()) {
            return;
        }

        var cloned = ko.mapping.fromJS(ko.toJS(self.user));
        self.temp(cloned);

        $('#modal-edit').modal('show');
    };

    ProfileVM.prototype.initHome = function() {
        this.init('home');
    };

    ProfileVM.prototype.init = function(action) {
        var self = this;
        var theData = { "params": {
            "type": typeof action === 'string' ? action : 'profile'
        }};

        if (Cookies.get('forgotpwd')) {
            document.location.hash = '#/user/profile';
            FerozoDhm.activeSection('profile');
            self.inprocess(0);
        }

        var customExtend = function(target, source) {
            if (source) {
                for(var prop in source) {
                    if(source.hasOwnProperty(prop)) {
                        //revisar arrays
                        source[prop] && (target[prop] = source[prop]);
                    }
                }
            }
            return target;
        };


        self.inprocess(1);
        return $.postJSON("/dhm/account/getinfo", theData, function(data) {
            self.user(new User(
                customExtend(ko.toJS(self.user()), data.result)
            ));
            if (! Cookies.get('hideModalMaxHosting')) {
                self.showModalMaxHosting(data.result.ShowModalMaxHostig);
                self.numMaxHosting(parseInt(data.result.MaxHostings));
                self.numTotalHosting(parseInt(data.result.TotalHostings));
            }

            if (data.result.enabled2fa == '1') {
                self.authFlag(true);
            }

            $('a[href$=logout]').click(function() {
                Cookies.del('hideModalMaxHosting');
            });

        }).always(function() {
            self.inprocess(0);
        });
    };

    ProfileVM.prototype.hideAndPreventMaxHosting = function(action) {
        this.showModalMaxHosting(false);
        Cookies.set('hideModalMaxHosting', true);
        window.location.href = '#/account/hostings';
        return true;
    };

    ProfileVM.prototype.get2fa = function(action) {
        var self = this;
        var theData = { "params": {
            "idDhm": self.user().idDhm()
        }};
        return $.postJSON("/dhm/account/gen2faqr", theData, function(data) {
            self.secret2fa(data.result.secret);
            self.ImageBase64(data.result.img);
        }).always(function() {
        });
    };

    ProfileVM.prototype.validate2fa = function(action) {
        var self = this;
        var theData = { "params": {
            "idDhm": self.user().idDhm(),
            "secret": self.secret2fa(),
            "code": self.authCode()
        }};
        return $.postJSON("/dhm/account/validate2faqr", theData, function(data) {
            if (data.result) 
                self.authFlag(true);
            if (data.error && data.error.data) {
                $.each(data.error.data.inputException, function() {
                    self.authCode.errors(this.errorDesc);
                });
            }
        }).always(function() {
        });
    };

    ProfileVM.prototype.reset2fa = function(action) {
        return $.postJSON("/dhm/account/reset2fa", function(data) {
            if (data.result) {
                FerozoDhm.profileVM().authFlag(false);
                FerozoDhm.profileVM().ImageBase64('');
                FerozoDhm.profileVM().secret2fa('');
                FerozoDhm.profileVM().authCode('');
                $('#modal-reset2fa').modal('hide');
            }  
        }).always(function() {
        });
    };

    ProfileVM.prototype.openModalReset2fa = function() {
        $('#modal-reset2fa').modal('show');
    };

    ko.bindingHandlers.enterkey = {
        init: function (element, valueAccessor, allBindings, viewModel) {
            var callback = valueAccessor();
            $(element).keypress(function (event) {
                var keyCode = (event.which ? event.which : event.keyCode);
                if (keyCode === 13) {
                    callback.call(viewModel);
                    return false;
                }
                return true;
            });
        }
    };

    return ProfileVM;
});