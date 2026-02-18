define(['knockout', 'ko.mapping', 'antispamconfig', 'spampattern', 'email', 'notifications'], function(ko, mapping, AntispamConfig, SpamPattern, Email, Notifications) {
    var antispammultipleVM = function() {
        'use strict';
        var self = this;

        mediator.installTo(this);
        ko.mapping = mapping;
        ko.bindingHandlers.checkedInArray = {
            init: function (element, valueAccessor, all, vm, bindingContext) {
                ko.utils.registerEventHandler(element, "click", function() {
                    var array = valueAccessor(), // don't unwrap array because we want to update the observable array itself
                        value = bindingContext.$data,
                        checked = element.checked;
                    ko.utils.addOrRemoveItem(array, value, checked);
                });
            },
            update: function (element, valueAccessor, all, vm, bindingContext) {
                var array = ko.utils.unwrapObservable(valueAccessor()),
                    value = bindingContext.$data;

                element.checked = ko.utils.arrayIndexOf(array, value) >= 0;
            }
        };

        var spamPattern = new SpamPattern(this);
        spamPattern.isTemplate = true;

        this.inprocess = ko.observable(1);
        this.data = ko.observableArray([]);
        this.emails = ko.observableArray([]);
        this.emailsSelected = ko.observableArray([]);
        this.temp = ko.observable(spamPattern);
        this.config = ko.observable(new AntispamConfig());
        this.step = ko.observable(1);

        this.sortDirectionEmails = ko.observable('asc');
        this.sortEmails = function() {
            var self = this;
            if (self.sortDirectionEmails() === 'des') {
                self.sortDirectionEmails('asc');
                self.emails.sort(function(left, right) {
                    return left.account.user() == right.account.user() ? 0 : (left.account.user() < right.account.user() ? -1 : 1);
                });
            } else {
                self.sortDirectionEmails('des');
                self.emails.sort(function(left, right) {
                    return left.account.user() == right.account.user() ? 0 : (left.account.user() > right.account.user() ? -1 : 1);
                });
            }
        };

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

        /** FILTRO DE TABLA POR JAVASCRIPT **/
        this.search = function(value) {
            value = typeof value === 'string' && value.trim() || '';
            var regex = new RegExp(value);
            ko.utils.arrayForEach(self.emails(), function(email) {
                email.visible(false);
                if (email.account.user().match(regex)) {
                    email.visible(true);
                }
            });
        };
        this.query = ko.observable('');
        this.query.subscribe(self.search);
        /** /FILTRO DE TABLA POR JAVASCRIPT **/

        this.subscribe('emailListUpdated', function(emails) {
            'use strict';

            var baseEmail = new Email();
            $.each(emails, function(i, email) {
                if (! email.antiSpamConfig) {
                    //workaround para cuando no se termino de crear el mail ya que vuelve como null
                    email.antiSpamConfig = baseEmail.antiSpamConfig;
                }
                email.visible = true;
            });

            var mapping = {
                create: function(options) {
                    return new Email(options.data);
                },
                key: function(item) {
                    return ko.utils.unwrapObservable(item.id);
                }
            };

            ko.mapping.fromJS(emails, mapping, self.emails);
            FerozoHosting.profileVM().user().updateEmails(emails);
            self.inprocess(0);
        });

        this.isWin = function() {
            if (typeof FerozoHosting.profileVM() !== "undefined" && typeof FerozoHosting.profileVM().user() !== "undefined") {
                return FerozoHosting.profileVM().user().Server.OpSystem() !== 'Linux';
            } else {
                return false;
            }        
        };        

        this.changeMoveOrDelete = function(entity, event) {
            'use strict';
            var value = $(event.target).val();
            self.config().deletespam(value);
        };

        this.init = function() {
            'use strict';
            mediator.publish('refreshEmailList');
            if (! self._initted) {
                self.query('');
                self.step(1);
                self.emailsSelected([]);
                self.list();
            }

            self.initRangeInputs();
            $(window).resize(function() {
                self.initRangeInputs();
            });

        };
    };

    antispammultipleVM.prototype.initRangeInputs = function() {
        $('.fz-rangeslider').each(function() {
            var range = $(this).find('input[type="range"]');
            var peaks = $(this).find('.peaks').html('');
            var pieces = parseInt(range.attr('max')) - parseInt(range.attr('min'));
            var margin = range.width() / pieces;
            var ul = $('<ul>');
            peaks.append(ul);
            ul.html('');
            var marginCounter = 0;
            $.each(FerozoHosting.range(range.attr('min') ,range.attr('max')), function() {
                var li = $('<li>').css({"left": marginCounter});
                marginCounter += (margin - 0.3);
                li.appendTo(ul);
            });
        });
        return true;
    };

    antispammultipleVM.prototype.selectAllEmails = function() {
        this.emailsSelected(this.emails());
    };

    antispammultipleVM.prototype.unselectAllEmails = function() {
        this.emailsSelected([]);
    };

    antispammultipleVM.prototype.getEmailsSelectedIds = function() {
        var ids = [];
        ko.utils.arrayForEach(this.emailsSelected(), function(email) {
            email.id && ids.push(email.id());
        });
        return ids;
    };

    antispammultipleVM.prototype.list = function(success) {
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

    antispammultipleVM.prototype.openModal = function() {
        'use strict';
        //var spamPattern = new SpamPattern(this);
        var spamPattern = new SpamPattern();
        spamPattern.isTemplate = true;
        this.temp(spamPattern);
        $('#modal-create').modal('show');
    };

    antispammultipleVM.prototype.goStep2 = function() {
        var self = this;
        self.step(2);
        var intervalId;
        intervalId = window.setInterval(function() {
            self.initRangeInputs();
            window.clearInterval(intervalId);
        }, 300);
    };

    antispammultipleVM.prototype.openSubjectModal = function() {
        'use strict';
        $('#modal-subject').modal('show');
    };

    antispammultipleVM.prototype.add = function() {
        if (this.temp().patternspam() && this.temp().patternspam().trim()) {
            this.data.push(this.temp());
            $('#modal-create').modal('hide');
        }
    };

    antispammultipleVM.prototype.remove = function(entity, event) {
        this.data.remove(entity);
    };

    antispammultipleVM.prototype.getPatternsByType = function(type) {
        var patterns = [];
        ko.utils.arrayForEach(this.data(), function(pattern) {
            pattern.type() === type && patterns.push(pattern.pattern());
        });
        return patterns;
    };

    antispammultipleVM.prototype.getPattern = function(type, patternStr) {
        var found;
        ko.utils.arrayForEach(this.data(), function(pattern) {
            if (pattern.type() === type && pattern.pattern() === patternStr) {
                found = pattern;
            }
        });
        return found;
    };

    antispammultipleVM.prototype.clearErrors = function() {
        ko.utils.arrayForEach(this.data(), function(pattern) {
            pattern.validationMsg && pattern.validationMsg('');
        });
    };

    antispammultipleVM.prototype.reset = function() {
        this.emailsSelected([]);
        this.step(1);
        this.data([]);
        this.init();
        this.query('');
    };

    antispammultipleVM.prototype.save = function() {
        'use strict';
        var self = this;

        var theData = { "params": {
            "score": self.isWin() ? self.config().scoreToMove() : self.config().score(),
            "scoretomove": self.isWin() ? self.config().scoreToMove() : null,
            "scoretodelete": self.isWin() ? self.config().scoreToDelete() : null,
            "deletespam": self.config().deletespam(),
            "subjecttag": self.config().subjectTag(),
            "subjectTag": self.config().subjectTag(),
            "spamlabel": self.config().subjectTag(),
            "filterTo": self.config().subjectTag(),
            "status": self.config().enabled(),
            "whiteListPatterns": self.getPatternsByType('white'),
            "blackListPatterns": self.getPatternsByType('black'),
            "idsEmail": self.getEmailsSelectedIds()
        }};
        self.inprocess(1);
        self.clearErrors();
        $.postJSON("/hosting/email/configureantispammassive", theData, function(data) {
            if (data.error) {
                if (data.error.data.inputException) {
                    Notifications.error($('#trans-invalid-patterns').html());
                    $.each(data.error.data.inputException, function() {
                        this.field = this.field === 'subject' ? 'subjectTag' : this.field;
                        if (this.field === 'whiteListPatterns' || this.field === 'blackListPatterns') {
                            var type = this.field === 'whiteListPatterns' ? 'white' : 'black';
                            var errorDesc = this.errorDesc;
                            ko.utils.arrayForEach(this.token, function(e) {
                                var pattern = self.getPattern(type, e);
                                pattern && pattern.validationMsg(errorDesc);
                            });
                        }
                        //$('#modal-subject input[data-bind="value: '+this.field+'"]').next('.help-block.error').html(this.errorDesc);
                    });
                }
            } else if (data.result) {
                FerozoHosting.tasksVM().init();
                Notifications.success($('#trans-save-async').html());
                self.reset();
            }
        }).fail(function(data) {
        }).always(function(data) {
            self.inprocess(0);
        });
    };

    return antispammultipleVM;
});