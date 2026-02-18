define(['knockout', 'ko.mapping', 'notifications'], function(ko, mapping, Notifications) {
    /* ------------ errorpage -----------------*/
    var Errorpage = function(data) {
        'use strict';

        this.errorType = ko.observable();
        this.file = ko.observable(false);
        this.fileName = ko.observable(null);
        this.html = ko.observable(null);
        this.desc = ko.observable(null);
        this.idHosting = ko.observable();
        this.isconfigured = ko.observable();

        this.currentContent = function() {
            if (this.fileName()) {
                var file = $('#trans-file').html();
                return file + ' (' + this.fileName() + ')';
            }
            if (this.html() && this.html().trim()) {
                return 'Html';
            }
            if (! (this.fileName() && this.html() && this.html().trim())) {
                return $('#trans-no-configured').html();
            }
        };

        mediator.installTo(this);
        ko.mapping = mapping;
        ko.mapping.fromJS(data, {}, this);
    };

    Errorpage.prototype.restaurar = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "errorType": self.errorType()
        }};

        FerozoHosting.errorpagesVM() && FerozoHosting.errorpagesVM().inprocess(1);
        $.postJSON('/hosting/tools/apache/errorpages/remove', theData, function(data) {
            Notifications.success($('#trans-restored-ok').html());
            mediator.publish('refreshErrorPages');
        }).always(function(data) {
            FerozoHosting.errorpagesVM() && FerozoHosting.errorpagesVM().inprocess(0);
        });
    };

    Errorpage.prototype.save = function() {
        'use strict';
        var self = this;

        if (! self.html()) {

        }
        var theData = { "params": {
            "errorType": self.errorType(),
            "html": $('.summernote').code()
        }};

        FerozoHosting.errorpagesVM() && FerozoHosting.errorpagesVM().inprocess(1);
        $.postJSON('/hosting/tools/apache/errorpages/configure', theData, function(e) {
            Notifications.success($('#trans-page-configured-ok').html() + ' (' + self.errorType() + ')');
            mediator.publish('refreshErrorPages');
        }).always(function() {
            FerozoHosting.errorpagesVM() && FerozoHosting.errorpagesVM().inprocess(0);
        });
    };

    Errorpage.prototype.openEdit = function(ErrorPage, e) {
        'use strict';
        FerozoHosting.errorpagesVM().temp(ErrorPage);
        $('#edit-errorpage').modal('show');
        this.initSummernote();
    };

    Errorpage.prototype.initSummernote = function() {
        $('.summernote').summernote({
            toolbar: [
                ['style', ['bold', 'italic', 'underline', 'clear']],
                ['fontsize', ['style']],
                ['para', ['ul', 'ol', 'paragraph']],
                ['misc', ['codeview']]
            ]
        });
        $('.summernote').next().find('.modal').hide();
        $('a[href="#"]').removeAttr('href');
        $(".note-codable").hide().addClass('span12');
        $('.fa.fa-code.icon-code').parents('button').on('click', function(e, i) {
            $(".note-editable").is(':visible') ? $(".note-editable").hide() : $(".note-editable").show();
            $(".note-codable").is(':visible') ? $(".note-codable").hide() : $(".note-codable").show();
        });
    };

    return Errorpage;
});