define(['knockout', 'notifications', 'translate'], function(ko, Notifications, Translate) {
    var whitelabelVM = function() {
        var self = this;

        self.changeFrom =  ko.observable();
        self.changeFrom.subscribe(function() {
            self.fileUrl('');
            self.file('');
        });
        self.fileUrl =  ko.observable();
        self.file =  ko.observable();
        self.file.subscribe(function() {
            self.file.errors('');
            if (self.file() && !self.isValidImageExt(self.file())) {
                self.file('');
                self.file.errors(Translate('#trans-file-not-image'));
            }
        });
        self.applyToAccounts =  ko.observable();
        self.inprocess =  ko.observable(0);
    };

    whitelabelVM.prototype.isValidImageExt = function(name) {
        return name && !!name.toString().match(/\.(jpg|jpeg|gif|png)$/i);
    };

    whitelabelVM.prototype.init = function() {
        var self = this;
        self.inprocess(0);
        self.file('');
        self.fileUrl('');
        self.changeFrom('');
        self.refreshLogo();
    };

    whitelabelVM.prototype.deleteLabel = function(option) {
        var self = this;
        var theData = { "params": {
            applyToAccounts: option
        }};
        
        $.postJSON("/dhm/account/unsetwhitelabel", theData, function(data) {
            if (data.result) {
                self.init();
            }
        }).always(function(data) {
            self.init();
        });

    };

    whitelabelVM.prototype.save = function(form) {
        var self = this;

        if (self.file()) {
            return self.uploadFromFile(form);
        }

        if (! self.fileUrl()) {
            return;
        }

        var theData = { "params": {
            logoImgUrl: self.fileUrl(),
            applyToAccounts: self.applyToAccounts()
        }};

        ko.utils.clearObservableErrors.bind(self).apply();
        self.inprocess(1);
        $.postJSON("/dhm/account/setwhitelabel", theData, function(data) {
            if (data.result) {
                self.init();
            }
        }).fail(function(data) {
        }).always(function(data) {
            if (data.error && data.error.data) {
                data.error.data.inputException && ko.utils.setObservableErrors.bind(self, data.error.data.inputException).apply();
            }
            self.inprocess(0);
        });
    };

    whitelabelVM.prototype.uploadFromFile = function(form) {
        var self = this;

        if (! self.file() && self.isValidImageExt(self.file())) {
            return;
        }

        var formData = new FormData(form);
        formData.append('applyToAccounts', self.applyToAccounts());
        //formData.append('file-1', '/public_html');
        ko.utils.clearObservableErrors.bind(self).apply();
        self.inprocess(1);
        $.ajax({
            "url": "/dhm/account/setwhitelabel",
            "type": 'POST',
            success: function(data) {
                if (data.error && data.error.data) {
                    data.error.data.inputException && ko.utils.setObservableErrors.bind(self, data.error.data.inputException).apply();
                    data.error.data.customerrors && Notifications.error(data.error.data.customerrors.value);
                    data.error.data.userException.value && Notifications.error(data.error.data.userException.value);
                } else {
                    Notifications.success($('#trans-upload-success').html());
                    var fileName = $('input[name="file"]').val().split('\\').pop();
                    self.init();
                }
                self.inprocess(0);
            }, error: function() {
                Notifications.error($('#trans-error-uploading-file').html());
                self.inprocess(0);
            },
            headers: { accept: 'application/json' },
            "data": formData,
            "cache": false,
            "contentType": false,
            "processData": false,
            "accepts": {
                "text": 'application/json',
                "json": 'application/json'
            }
        }, 'json');
    };

    whitelabelVM.prototype.revert = function() {
        var self = this;

        var theData = { "params": {
            action: "revert"
        }};

        self.inprocess(1);
        ko.utils.clearObservableErrors.bind(self).apply();
        $.postJSON("", theData, function(data) {
        }).always(function(data) {
            self.inprocess(0);
            self.init();
        });
    };

    whitelabelVM.prototype.refreshLogo = function() {
        $('img[src^="/common/logo"]').attr('src', '/common/logo?refresh=' + Math.random());
    };

    return whitelabelVM;
});