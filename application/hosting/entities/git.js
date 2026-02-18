define(['knockout', 'ko.mapping', 'input'], function(ko, mapping, Input) {
    var Git = function(data) {
        'use strict';

        mediator.installTo(this);
        ko.mapping = mapping;
        this.rowstatus = ko.observable('0');//0=nada;1=delete
        this.repository = new Input();
        this.folder = new Input();
        this.branch = new Input();
        var mappingRules = {
            'repository': {
                create: function(options) {
                    return new Input({
                        'content': options.data
                    });
                }
            },
            'folder': {
                create: function(options) {
                    return new Input({
                        'content': options.data
                    });
                }
            },
            'branch': {
                update: function(options) {
                    return  options.data;
                }
            }
        };
        ko.mapping.fromJS(data, mappingRules, this);
    };

    Git.prototype.remove = function() {
        'use strict';
        var self = this;
        
        var theData = { "params": {
            "idRepository": self.id()
        }};
        
        self.regStatus(4);
        $.postJSON('/hosting/tools/removegit', theData, function(e) {
            FerozoHosting.gitVM() && FerozoHosting.gitVM().inprocess(1);
            mediator.publish('refreshGit');
        }).fail(function(data) {
            self.regStatus(1);
        }).always(function(data) {
            data.error && self.regStatus(1);
            FerozoHosting.gitVM() && FerozoHosting.gitVM().inprocess(0);
        });
    };

    Git.prototype.save = function() {
        'use strict';
        var self = this;
        var theData = { "params": {
            "repository": self.repository.content(),
            "branch": self.branch.content(),
            "folder": self.folder.content() ? self.folder.content() : '/'
        }};

        FerozoHosting.gitVM() && FerozoHosting.gitVM().inprocess(1);
        $.postJSON('/hosting/tools/addgit', theData, function(response) {
            $.each(theData.params,function(i,v) {if (typeof self[i] != 'undefined' && typeof self[i].clearErrors == "function") {self[i].clearErrors()}});
            if (response.error && response.error.data.inputException) {
                $.each(response.error.data.inputException, function() {
                    self[this.field].error(this.errorDesc);
                });
            } else {
                mediator.publish('refreshGit');
                $('.modal').modal('hide');
            }
        }).always(function() {
            FerozoHosting.gitVM() && FerozoHosting.gitVM().inprocess(0);
        });
    };

    return Git;
});