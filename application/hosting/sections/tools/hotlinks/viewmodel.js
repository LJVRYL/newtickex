define(['knockout', 'hotlink', 'ko.mapping'], function(ko, Hotlink, mapping) {

    var HotlinksVM = function() {
        'use strict';
        var self = this;
        mediator.installTo(this);
        ko.mapping = mapping;

        this.temp = ko.observable(new Hotlink());
        this.inprocess = ko.observable(1);

        this.data = ko.observableArray([]);
        this.extensions = ko.observableArray([]);
        this.redirection = ko.observable('');
        this.enabled = ko.observable(false);

        this.sortDirection = ko.observable('asc');
        this.sortData = function() {
            var self = this;
            if (self.sortDirection() === 'des') {
                self.sortDirection('asc');
                self.data.sort(function(left, right) {
                    return left.url() == right.url() ? 0 : (left.url() < right.url() ? -1 : 1);
                });
            } else {
                self.sortDirection('des');
                self.data.sort(function(left, right) {
                    return left.url() == right.url() ? 0 : (left.url() > right.url() ? -1 : 1);
                });
            }
        };

        this.subscribe('refreshHotlinks', function() {
            self.list();
        });
    };

    HotlinksVM.prototype.modalCreate = function() {
        this.temp(new Hotlink());
        $('#modal-create').modal('show');
    };

    HotlinksVM.prototype.displayTable = function() {
        return this.extensions() && this.extensions()[0] && this.redirection();
    };

    HotlinksVM.prototype.list = function() {
        var self = this;
        self.inprocess(1);
        self.data([]);
        $.postJSON("/hosting/tools/apache/hotlinks/list", function(data) {
            if (typeof data.result.configValue === 'object' && !$.isEmptyObject(data.result.configValue)) {
                self.extensions(data.result.configValue.extensions);
                self.enabled(data.result.configValue.enabled);
                self.redirection(data.result.configValue.redirection);
                $.each(data.result.configValue.urls, function(i,e) {
                    var entity = {"url": e};
                    self.data.push(new Hotlink(entity));
                });
            }
            self.initTagsInput();
        }).always(function(data) {
            self.inprocess(0);
        });;
    };

    HotlinksVM.prototype.getRawUrls = function(exclude) {
        var self = this;
        var urls = [];
        $.each(self.data(), function(i,e) {
            if (e.url() !== exclude) {
                urls.push(e.url());
            }
        });
        return urls;
    };

    HotlinksVM.prototype.configure = function(action, hotlink) {
        var self = this;
        var extensions = typeof self.extensions() === "string" ? self.extensions().split(',') : self.extensions();
        var theData = {"params": {
            "enabled": self.enabled(),
            "extensions": extensions,
            "redirection": self.redirection(),
            "urls": self.getRawUrls(action === 'remove' ? hotlink.url() : null)
        }};

        $('.help-block.error').html('');
        if (action === 'save') {
            if (! (hotlink.url() && hotlink.url().trim() && hotlink.url().match(/[a-z\d]{3,}\./))) {
                $('input[name^="url"]').parent().find('.help-block.error').html('Ingrese una URL valida');
                return;
            }
        }

        self.inprocess(1);
        $.postJSON("/hosting/tools/apache/hotlinks/configure", theData, function(data) {
            if (data.error && data.error.data.inputException) {
                $.each(data.error.data.inputException, function() {
                    this.field = this.field === 'extension' ? 'extensions' : this.field;
                    $('input[name^="' + this.field + '"]').parent().find('.help-block.error').html(this.errorDesc);
                });
            } else if (data.result) {
                if (action === 'remove') {
                    self.data.remove(hotlink);
                } else if (action === 'save') {
                    mediator.publish('refreshHotlinks');
                }
                $('#modal-create').modal('hide');
            }
        }).always(function(data) {
            self.inprocess(0);
        });;
    };

    HotlinksVM.prototype.init = function() {
        'use strict';
        mediator.publish('refreshHotlinks');
        this.initTagsInput();
    };

    HotlinksVM.prototype.initTagsInput = function() {
        var extensionsField = $('#extensions');
        try {
            extensionsField.tagsinput('destroy');
            extensionsField.tagsinput({
                itemText: function(ext) {
                    return ext.toLowerCase();
                },
                tagClass: function() {
                    return 'label label-warning';
                },
                maxTags: 15
            });
            $('.bootstrap-tagsinput input').on('blur', function() {
                extensionsField.tagsinput('add', $(this).val());
                $(this).val('');
            });
        } catch (e) {}
    };

    return HotlinksVM;
});