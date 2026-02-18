define(['knockout', 'ko.mapping', 'treeNavigation', 'notifications', 'ftp'], function(ko, mapping, TreeNavigation, Notifications, Ftp) {

    var uploadsiteVM = function() {
        'use strict';
        var self = this;
        ko.mapping = mapping;

        self.inprocess = ko.observable(0);
        self.data = ko.observableArray([]);
        self.treeNavigationSelect = new TreeNavigation();
        self.treeNavigationExtract = new TreeNavigation();
        self.currentModal = ko.observable(self.treeNavigationSelect);
        self.file = ko.observable();
        self.uploadFileErrorMsg = ko.observable();

        self.uploadFileUrl = "/hosting/filemanager/upload";
        self.extractFileUrl = "/hosting/filemanager/zipped/extract";
        self.ftpInfo = ko.observable(new Ftp());

        this.init = function() {
            'use strict';
            Notifications.timeout = 10000;

            self.inprocess(0);
            self.treeNavigationSelect.onlyFolders = false;
            self.treeNavigationSelect.onlyExtensions = ["tar.gz", "gz", "zip"];
            self.treeNavigationSelect.allowSelectedFiles = true;
            self.treeNavigationSelect.allowSelectedFolders = false;

            self.treeNavigationExtract.onlyFolders = true;
            self.treeNavigationExtract.onlyExtensions = [];
            self.treeNavigationExtract.allowSelectedFiles = false;
            self.treeNavigationExtract.allowSelectedFolders = true;
        };

        this.onFileSelection = function() {
            $('#fileNavigator').modal('hide');

            //if (self.currentModal() === self.treeNavigationSelect) {
            //    self.currentModal(self.treeNavigationExtract);
            //    self.currentModal().reset();
            //    self.currentModal().list();
            //} else if (self.currentModal() === self.treeNavigationExtract) {
            //    $('#fileNavigator').modal('hide');
            //}
        };

        this.extractFile = function() {
            var theData = { "params": {
                "sourceFile":  self.treeNavigationSelect.selected(),
                "destination":  self.treeNavigationExtract.selected()
            }};

            self.inprocess(1);
            $.postJSON(self.extractFileUrl, theData, function(data) {
                Notifications.success($('#trans-extraction-started').html().replace('%s', self.treeNavigationExtract.selected()));
                self.treeNavigationSelect.reset();
                self.treeNavigationExtract.reset();
            }).fail(function() {
                Notifications.error($('#trans-unexpected-error-extracting').html());
            }).always(function() {
                self.inprocess(0);
            });
        };

        this.useRootDir = function() {
            self.treeNavigationExtract.selected('/public_html/');
            self.onFileSelection();
        };
    };

    uploadsiteVM.prototype.isWin = function() {
        if (typeof FerozoHosting.profileVM() !== "undefined" && typeof FerozoHosting.profileVM().user() !== "undefined") {
            return FerozoHosting.profileVM().user().Server.OpSystem() !== 'Linux';
        } else {
            return false;
        }        
    };
    
    uploadsiteVM.prototype.getHostingPpalDomain = function() {
        if (typeof FerozoHosting.profileVM() !== "undefined" && typeof FerozoHosting.profileVM().user() !== "undefined") {
            return FerozoHosting.profileVM().user().PpalDomain.Name();
        } else {
            return '';
        }
    };    
    
    uploadsiteVM.prototype.listFtp = function() {
        var self = this;
        self.inprocess(1);
        $.postJSON("/hosting/ftp/listftpaccounts", function(data) {
            if (data.result) {
                $.each(data.result, function() {
                    self.ftpInfo(new Ftp(this));
                    return;
                });
                FerozoHosting.profileVM() && FerozoHosting.profileVM().user().updateFtps(data.result);
            }
        }).always(function(data) {
            self.inprocess(0);
        });
    };

    uploadsiteVM.prototype.showModalSelectFile = function() {
        var self = this;
        self.currentModal(self.treeNavigationSelect);

        $('#fileNavigator').modal('show');
        self.currentModal().reset();
        self.currentModal().list();
    };

    uploadsiteVM.prototype.showModalFtp = function() {
        this.listFtp();
        $('#ftpInfo').modal('show');
    };

    uploadsiteVM.prototype.showModalExtractFile = function() {
        var self = this;
        self.currentModal(self.treeNavigationExtract);

        $('#fileNavigator').modal('show');
        self.currentModal().reset();
        self.currentModal().list();
    };

    uploadsiteVM.prototype.showModalUploadFile = function() {
        var self = this;
        self.file('');
        self.currentModal().reset();
        $('#uploadFile').modal('show');
    };

    uploadsiteVM.prototype.validateFile = function(inputFile) {
        var self = this;
        var file = inputFile.files[0];

        self.uploadFileErrorMsg('');
        var extRegexp = self.isWin() ? /(zip)$/ : /(gz|zip|tar)$/;
        if (file && !file.name.toString().match(extRegexp)) {
            self.file('');

            self.uploadFileErrorMsg($('#trans-invalid-file-extension').html());
        };
    };

    uploadsiteVM.prototype.uploadFile = function(form) {
        var self = this;

        if (! self.file()) {
            return;
        }

        var formData = new FormData(form);
        formData.append('destination', '/public_html');

        $('.help-block.error').html('');
        self.inprocess(1);
        $.ajax({
            "url": self.uploadFileUrl,
            "type": 'POST',
            success: function(data) {
                if (data.error) {
                    if (data.error.data && data.error.data.userException) {
                        Notifications.error(data.error.data.userException.value);
                    }
                    if (data.error.data && data.error.data.customerrors) {
                        Notifications.error(data.error.data.customerrors.value);
                    }
                    if (data.error.data && data.error.data.inputException) {
                        $.each(data.error.data.inputException, function() {
                            $('input[name^="' + this.field + '"]').parent().parent().find('.help-block.error').html(this.errorDesc);
                        });
                    }
                } else {
                    Notifications.success($('#trans-upload-success').html());
                    var fileName = $('input[name="file"]').val().split('\\').pop();
                    self.treeNavigationSelect.selected('/public_html/' + fileName);
                    $('#uploadFile').modal('hide');
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

    return uploadsiteVM;
});
