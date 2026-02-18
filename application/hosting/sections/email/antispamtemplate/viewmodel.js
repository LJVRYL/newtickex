define(['knockout', 'ko.mapping', 'antispamconfig', 'spampattern'], function(ko, mapping, AntispamConfig, SpamPattern) {
    var antispamtemplateVM = function() {
        'use strict';
        var self = this;
        var spamPattern = new SpamPattern(this);
        spamPattern.isTemplate = true;

        mediator.installTo(this);
        ko.mapping = mapping;
        this.inprocess = ko.observable(1);

        this.data = ko.observableArray([]);
        this.temp = ko.observable(spamPattern);
        this.config = ko.observable(new AntispamConfig());

        this.sortDirection = ko.observable('asc');
        this.sortData = function() {
            var self = this;
            if (self.sortDirection() === 'des') {
                self.sortDirection('asc');
                self.data.sort(function(left, right) {
                    return left.pattern == right.pattern ? 0 : (left.pattern < right.pattern ? -1 : 1);
                });
            } else {
                self.sortDirection('des');
                self.data.sort(function(left, right) {
                    return left.pattern == right.pattern ? 0 : (left.pattern > right.pattern ? -1 : 1);
                });
            }
        };
        this.sortDirectionType = ko.observable('asc');
        this.sortDataByType = function() {
            var self = this;
            if (self.sortDirectionType() === 'des') {
                self.sortDirectionType('asc');
                self.data.sort(function(left, right) {
                    return left.type() == right.type() ? 0 : (left.type() < right.type() ? -1 : 1);
                });
            } else {
                self.sortDirectionType('des');
                self.data.sort(function(left, right) {
                    return left.type() == right.type() ? 0 : (left.type() > right.type() ? -1 : 1);
                });
            }
        };

        this.isWin = function() {
            return FerozoHosting.profileVM().user().Server.OpSystem() === 'Windows';
        };

        this.changeScore = function() {
            'use strict';

            var theData = { "params": {
                "score": self.isWin() ? self.config().scoreToMove() : self.config().score(),
                "scoretomove": self.config().scoreToMove(),
                "scoretodelete": self.config().scoreToDelete(),
                "deletespam": self.config().deletespam(),
                "subjecttag": self.config().subjectTag(),
                "spamlabel": self.config().subjectTag(),
                "filterTo": self.config().subjectTag()
            }};

            self.inprocess(1);
            $.postJSON("/hosting/email/antispamtemplate/changescore", theData, function(data) {
                if (data.error) {
                    if (data.error.data.inputException) {
                        $.each(data.error.data.inputException, function() {
                            this.field = this.field === 'subject' ? 'subjectTag' : this.field;
                            $('#modal-subject input[data-bind="value: '+this.field+'"]').next('.help-block.error').html(this.errorDesc);
                        });
                    }
                } else if (data.result) {
                    $.each(data.result, function() {});
                    $('#modal-subject').modal('hide');
                }
            }).fail(function(data) {
            }).always(function(data) {
                self.inprocess(0);
            });
        };

        this.changeMoveOrDelete = function(entity, event) {
            'use strict';
            var value = $(event.target).val();
            self.config().deletespam(value);
            self.changeScore();
        };

        this.changeSubject = function(entity, event) {
            'use strict';
            self.changeScore();
        };

        this.init = function() {
            'use strict';
            self.list();
        };
    };

    antispamtemplateVM.prototype.toggleStatus = function() {
        var self = this;
        var currentMode = self.config().enabled();
        var theData = { "params": {
            "status": !currentMode,
            "enabled": !currentMode
        }};

        self.inprocess(1);
        $.postJSON("/hosting/email/antispamtemplate/changestatus", theData, function(data) {
            if (data.result) {
                self.config().enabled(!currentMode);
            }
        }).fail(function(data) {
        }).always(function(data) {
            self.inprocess(0);
        });
    };

    antispamtemplateVM.prototype.list = function(success) {
        'use strict';
        var self = this;
        var theData = { "params": {

        }};

        self.inprocess(1);
        $.postJSON("/hosting/email/antispamtemplate/listconfig", theData, function(data) {
            self.data([]);
            if (data.result) {
                data.result.spamlabel = data.result.spamLabel;
                data.result.subjectTag = data.result.spamLabel;
                self.config(new AntispamConfig(data.result));
                $.each(data.result.bothList, function() {
                    var spamPattern = new SpamPattern(this);
                    spamPattern.isTemplate = true;
                    self.data.push(spamPattern);
                });
            }
        }).fail(function(data) {
        }).always(function(data) {
            self.inprocess(0);
        });
    };

    antispamtemplateVM.prototype.openModal = function() {
        'use strict';

        var spamPattern = new SpamPattern(this);
        spamPattern.isTemplate = true;
        //spamPattern.email(this.config());
        this.temp(spamPattern);
        $('#modal-create').modal('show');
    };

    antispamtemplateVM.prototype.openSubjectModal = function() {
        'use strict';
        $('#modal-subject').modal('show');
    };

    return antispamtemplateVM;
});