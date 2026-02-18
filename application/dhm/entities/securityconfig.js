define(['knockout', 'ko.mapping', 'notifications', 'translate'], function(ko, mapping, Notifications, Translate) {
    var SecurityConfig = function(data) {
        this.active  = ko.observable();
        this.max_attempts  = ko.observable();
        this.threshold  = ko.observable();
        this.first_penalty_minutes  = ko.observable();
        this.second_penalty_minutes  = ko.observable();
        this.third_penalty_hours  = ko.observable();
        this.ignore_same_ipuser  = ko.observable();

        ko.mapping = mapping;
        ko.mapping.fromJS(data, {}, this);
    };

    SecurityConfig.prototype.getSecurityConfigVM = function() {
        return FerozoDhm && FerozoDhm.securityVM();
    };

    SecurityConfig.prototype.save = function() {
        var self = this;
        var theData = { "params": self.toJS() };
        ko.utils.clearObservableErrors.bind(self).apply();

        self.getSecurityConfigVM().inprocess(1);
        $.postJSON('/dhm/serverconfig/ipcontrol/set', theData, function(data) {
            if (data.result) {
                Notifications.success(data.result.successMsg);
            }
        }).fail(function(data) {
        }).always(function(data) {
            if (data.error && data.error.data) {
                data.error.data.inputException && ko.utils.setObservableErrors.bind(self, data.error.data.inputException).apply();
            }
            self.getSecurityConfigVM().savedConfig(data.result);
            self.getSecurityConfigVM().inprocess(0);
        });
    };

    SecurityConfig.prototype.toJS = function() {
        var obj = ko.toJS(this, {ignore: ["__ko_mapping__"]});
        delete obj.__ko_mapping__;
        return obj;
    };

    return SecurityConfig;
});