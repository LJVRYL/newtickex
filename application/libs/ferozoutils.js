var FerozoUtils = FerozoUtils || {};

FerozoUtils.security = {
    "token": null,

    getToken: function() {
        return this.token ? this.token : this.requestToken();
    },

    requestToken: function() {
        var self = this;
        $.ajax({
            "url": '/common/security/csrf/token/get',
            "async": false,
            "dataType": 'json',
            "contentType": 'application/json',
            success: function(data) {
                self.token = data.result && data.result.token;
            }
        });
        return self.token;
    }
};