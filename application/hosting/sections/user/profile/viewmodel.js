define(['knockout', 'user', 'cookies'], function(ko, User, Cookies) {
    var ProfileVM = function() {

        this.user = ko.observable(new User());
        this.temp = ko.observable(new User());
        this.inprocess =  ko.observable(0);
        this.inprocessSettings =  ko.observable(0);

        this.showPassword = ko.observable();
        this.displayContactModal = ko.observable(false);
        this.displaySuspensionAlertModal = ko.observable(false);

        this.secret2fa = ko.observable('');
        this.ImageBase64 = ko.observable('');
        this.authCode = ko.observable('');
        this.authFlag = ko.observable(false);
        this.displayUser = ko.observable('');
    };

    ProfileVM.prototype.isLinux = function() {
        return this.user().Server.OpSystem() === 'Linux';
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
            FerozoHosting.activeSection('profile');
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
        return $.postJSON("/hosting/account/getinfo", theData, function(data) {
            self.user(new User(
                customExtend(ko.toJS(self.user()), data.result)
            ));
            if (! Cookies.get('hideShowSuspensionAlertModal')) {
                self.displaySuspensionAlertModal(data.result.Suspended.Value);
            }
            
            if (! Cookies.get('hideShowContactModal')) {
                self.displayContactModal(! data.result.Contact);
            }

            if (data.result.enabled2fa == '1') {
                self.authFlag(true);
            }

            if(data.result.Server.OpSystem == 'Linux')
                self.displayUser(data.result.UserName);
            else
                self.displayUser(data.result.UserName.split("@")[0]);
            
            $('a[href$=logout]').click(function() {
                Cookies.del('hideShowContactModal');
                Cookies.del('hideShowSuspensionAlertModal');
            });

        }).always(function() {
            self.inprocess(0);
        });
    };

    ProfileVM.prototype.hideAndPreventContactModal = function(action) {
        this.displayContactModal(false);
        Cookies.set('hideShowContactModal', true);
    };

    ProfileVM.prototype.hideAndPreventSuspensionAlertModal = function(action) {
        this.displaySuspensionAlertModal(false);
        Cookies.set('hideShowSuspensionAlertModal', true);
    };
    
    ProfileVM.prototype.isInArrayMotives = function(motive) {
        var aMotives = this.user().Suspended.Motives();
        if(aMotives && aMotives.indexOf(motive) >= 0) {
            return true;
        } else {
            return false;
        }
    };    

    ProfileVM.prototype.get2fa = function(action) {
        var self = this;
        var theData = { "params": {
            "idHosting": self.user().idHosting()
        }};
        return $.postJSON("/hosting/account/gen2faqr", theData, function(data) {
            self.secret2fa(data.result.secret);
            self.ImageBase64(data.result.img);
        }).always(function() {
        });
    };

    ProfileVM.prototype.validate2fa = function(action) {
        var self = this;
        var theData = { "params": {
            "idHosting": self.user().idHosting(),
            "secret": self.secret2fa(),
            "code": self.authCode()
        }};
        return $.postJSON("/hosting/account/validate2faqr", theData, function(data) {
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
        return $.postJSON("/hosting/account/reset2fa", function(data) {
            if (data.result) {
                FerozoHosting.profileVM().authFlag(false);
                FerozoHosting.profileVM().ImageBase64('');
                FerozoHosting.profileVM().secret2fa('');
                FerozoHosting.profileVM().authCode('');
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