define(['knockout', 'ko.mapping', 'notifications', 'translate'], function(ko, mapping, Notifications, Translate) {
    var MassLogin = function(data) {
        this.active  = ko.observable();
        this.max_attempts  = ko.observable();
        this.threshold  = ko.observable();

        ko.mapping = mapping;
        ko.mapping.fromJS(data, {}, this);
    };

    MassLogin.prototype.getSecurityConfigVM = function() {
        return FerozoDhm && FerozoDhm.securityVM();
    };

    MassLogin.prototype.save = function() {
        var self = this;
        var theData = { "params": self.toJS() };
        ko.utils.clearObservableErrors.bind(self).apply();

        self.getSecurityConfigVM().inprocess(1);
        $.postJSON('/dhm/serverconfig/ipmasslogin/set', theData, function(data) {
            if (data.result) {
                Notifications.success(data.result.successMsg);
            }
        }).fail(function(data) {
        }).always(function(data) {
            if (data.error && data.error.data) {
                data.error.data.inputException && ko.utils.setObservableErrors.bind(self, data.error.data.inputException).apply();
            }
            self.getSecurityConfigVM().inprocess(0);
        });
    };

    MassLogin.prototype.toJS = function() {
        var obj = ko.toJS(this, {ignore: ["__ko_mapping__"]});
        delete obj.__ko_mapping__;
        return obj;
    };

    return MassLogin;
});