define(['knockout', 'errorpage', 'ko.mapping', 'notifications'], function(ko, Errorpage, mapping, Notifications) {
    var Pages = [/*{
        "errorType": 400,
        "file": null,
        "fileName": null,
        "html": null,
        "isconfigured": false
    }, {
        "errorType": 401,
        "file": null,
        "fileName": null,
        "html": null,
        "isconfigured": false
    }, */{
        "errorType": 403,
        "file": null,
        "fileName": null,
        "html": null,
        "isconfigured": false
    }, {
        "errorType": 404,
        "file": true,
        "fileName": null,
        "html": null,
        "isconfigured": false
    }, {
        "errorType": 500,
        "file": true,
        "fileName": null,
        "html": null,
        "isconfigured": false
    }];

    var ErrorpagesVM = function() {
        'use strict';
        var self = this;
        mediator.installTo(this);
        ko.mapping = mapping;
        this.title = "";
        this.Pages = Pages;
        this.data = ko.observableArray([]);
        this.temp = ko.observable(new Errorpage());
        this.inprocess = ko.observable(0);
        this.hasValidFile = ko.observable(true);

        this.subscribe('refreshErrorPages', function() {
            'use strict';
            var self = this;
            var mapp = {
                create: function(options) {
                    return new Errorpage(options.data);
                },
                key: function(item) {
                    return ko.utils.unwrapObservable(item.errorType);
                }
            };

            var completePages = function(result) {
                $.each(Pages, function() {
                    var Page = this;
                    if (result.errorType == Page.errorType) {
                        Page.file = result.file;
                        Page.fileName = result.fileName;
                        Page.html = result.html;
                        Page.isconfigured = true;
                    }
                });
            };

            var clearPage = function(code) {
                $.each(Pages, function() {
                    var Page = this;
                    if (Page.errorType == code) {
                        Page.file = null;
                        Page.fileName = null;
                        Page.html = null;
                        Page.isconfigured = false;
                    };
                });
            };

            var clearPages = function() {
                $.each(Pages, function() {
                    var Page = this;
                    clearPage(Page.errorType);
                });
            };

            self.inprocess(1);
            $.postJSON('/hosting/tools/apache/errorpages/list', function(response) {
                if (response.result.length >= 1) {
                    clearPages();
                    $.each(response.result, function() {
                        var Result = this;
                        completePages(Result);
                    });
                } else {
                    clearPages();
                }

                ko.mapping.fromJS(Pages, mapp, self.data);
            }).always(function(data) {
                self.inprocess(0);
            });
        });

        this.hasFile = function(inputFile) {
            return $('#errorpage-file').val();
        };

        this.validateFile = function(inputFile) {
            var file = inputFile.files[0];
            var $alert = $('#invalid-file-alert').find('.error-msg').first().html('');
            self.hasValidFile(false);
            if (file.size > 1000000) {
                $alert.html("El archivo es muy grande. Intenta mantenerte debajo de 1MB");
                $('#errorpage-file').val('');
                return;
            } else if (file.type !== 'text/html' || !file.name.toString().match(/(html|htm)$/)) {
                $alert.html("El archivo debe ser HTML");
                $('#errorpage-file').val('');
                return;
            }
            self.hasValidFile(true);
        };

        this.sendFile = function(form) {
            var formData = new FormData(form);
            self.inprocess(1);
            $.ajax({
                "url": '/hosting/tools/apache/errorpages/configure',
                "type": 'POST',
                success: function(data) {
                    Notifications.success('Archivo subido correctamente');
                    $('#edit-errorpage').modal('hide');
                    self.inprocess(0);
                    FerozoHosting.errorpagesVM().init();
                }, error: function() {
                    Notifications.error('Error al subir el archivo');
                    self.inprocess(0);
                },
                "data": formData,
                "cache": false,
                "contentType": false,
                "processData": false,
                "accepts": {
                    "text": "application/json"
                }
            }, 'json');
        };

        this.init = function() {
            'use strict';
            mediator.publish('refreshErrorPages');
        };
    };
    return ErrorpagesVM;
});