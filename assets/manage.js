var filter;
var deleted;
var fulladmin = mw.config.exists('commentadmin');
var children = new Object();

function createThread(post) {
	var thread = new Thread();
	var object = thread.object;

	thread.init(post);
	if (post.parentid) {
		if (!children[post.parentid]) children[post.parentid] = new Array();
		children[post.parentid].push(post.id);
	}

	if (!deleted) {
		thread.addButton('reply', mw.msg('flowthread-ui-reply'), function() {
			thread.reply();
		});

		// Users with management privilege should have signed in.
		thread.addButton('like', mw.msg('flowthread-ui-like') + '(' + post.like + ')', function() {
			if (object.find('.comment-like').first().attr('liked') !== undefined) {
				thread.dislike();
			} else {
				thread.like();
			}
		});

		thread.addButton('report', mw.msg('flowthread-ui-report') + '(' + post.report + ')', function() {
			if (object.find('.comment-report').first().attr('reported') !== undefined) {
				thread.dislike();
			} else {
				thread.report();
			}
		});

		thread.addButton('delete', mw.msg('flowthread-ui-delete'), function() {
			thread.delete();
		});

		if (post.report && fulladmin) {
			thread.addButton('markchecked', mw.msg('flowthread-ui-markchecked'), function() {
				thread.markchecked();
			});
		}
	} else {
		thread.addButton('recover', mw.msg('flowthread-ui-recover'), function() {
			thread.recover();
		});
		if (fulladmin) {
			thread.addButton('delete', mw.msg('flowthread-ui-erase'), function() {
				thread.erase();
			});
		}
	}

	object.find('.comment-avatar').click(function() {
		object.toggleClass('comment-selected');
		onSelect();
	});

	if (post.myatt === 1) {
		object.find('.comment-like').attr('liked', '');
	} else if (post.myatt === 2) {
		object.find('.comment-report').attr('reported', '');
	}

	return thread;
}

Thread.prototype.recover = function() {
	var api = new mw.Api();
	api.post({
		action: 'flowthread',
		type: 'recover',
		postid: this.post.id
	});
	this.removeChildren();
	this.object.remove();
};

Thread.prototype.markchecked = function() {
	var api = new mw.Api();
	api.post({
		action: 'flowthread',
		type: 'markchecked',
		postid: this.post.id
	});
	if (filter === 'reported') {
		this.removeChildren();
		this.object.remove();
	} else {
		this.object.find('.comment-markchecked').remove();
		this.object.find('.comment-report').text(mw.msg('flowthread-ui-report') + '(0)');
	}
};

Thread.prototype.erase = function() {
	var api = new mw.Api();
	api.post({
		action: 'flowthread',
		type: 'erase',
		postid: this.post.id
	});
	this.removeChildren();
	this.object.remove();
};

Thread.remove = function(threads) {
	threads.forEach(function(t) {
		t.object.remove();
	})
};

Thread.join = function(threads) {
	return threads.map(function(t) {
		return t.post.id;
	}).join('|');
};

Thread.removeWithChildren = function(threads) {
	threads.forEach(function(t) {
		t.removeChildren();
		t.object.remove();
	})
};

Thread.delete = function(threads) {
	var api = new mw.Api();
	api.post({
		action: 'flowthread',
		type: 'delete',
		postid: Thread.join(threads)
	});
	Thread.removeWithChildren(threads);
};

Thread.recover = function(threads) {
	var api = new mw.Api();
	api.post({
		action: 'flowthread',
		type: 'recover',
		postid: Thread.join(threads)
	});
	Thread.remove(threads);
};

Thread.erase = function(threads) {
	var api = new mw.Api();
	api.post({
		action: 'flowthread',
		type: 'erase',
		postid: Thread.join(threads)
	});
	Thread.removeWithChildren(threads);
};

Thread.markchecked = function(threads) {
	var api = new mw.Api();
	api.post({
		action: 'flowthread',
		type: 'markchecked',
		postid: Thread.join(threads)
	});
	threads.forEach(function(item) {
		if (filter === 'reported') {
			item.object.remove();
		} else {
			item.object.find('.comment-markchecked').remove();
			item.object.find('.comment-report').text(mw.msg('flowthread-ui-report') + '(0)');
		}
	});
};

function reloadComments() {
	replyBox.remove();
}

// Retrieve params from URL
function getParams() {
	// Deliberate global value update
	filter = mw.util.getParamValue('filter') || 'all';
	deleted = filter === 'deleted' || filter == 'spam';
	var limit = parseInt(mw.util.getParamValue('limit')) || 20;
	var query = {
		filter: filter,
		limit: limit,
	};
	var from = mw.util.getParamValue('from');
	var offset = parseInt(mw.util.getParamValue('offset'));
	if (from) query.from = from;
	else if (offset) query.offset = offset;
	var page = mw.util.getParamValue('page');
	if (page) query.page = page;
	var user = mw.util.getParamValue('user');
	if (user) query.user = user;
	var keyword = mw.util.getParamValue('keyword');
	if (keyword) query.keyword = keyword;
	var dir = mw.util.getParamValue('dir');
	if (dir) query.dir = dir;
	return query;
}

function loadComments() {
	var query = getParams();

	var apiQuery = $.extend({
		action: 'query',
		list: 'allcomments',
		utf8: '',
	}, query);
	// "All" comments actually exclude deleted and spam comments!
	if (apiQuery.filter == 'all') apiQuery.filter = 'normal';
	apiQuery.dir = apiQuery.dir === 'prev' ? 'newer' : 'older';
	$.each({page: 'title', from: 'continue'}, function(from, to) {
		if (from in apiQuery) {
			apiQuery[to] = apiQuery[from];
			delete apiQuery[from];
		}
	});
	apiQuery.pager = true;
	$.each(['filter', 'title', 'user', 'keyword', 'dir', 'limit', 'offset', 'continue', 'pager'], function(index, param) {
		if (param in apiQuery) {
			apiQuery['cl' + param] = apiQuery[param];
			delete apiQuery[param];
		}
	});

	var api = new mw.Api();
	api.get(apiQuery).done(function(data) {
		var res = data.query.allcomments;
		$('.comment-container').html('');
		res.posts.forEach(function(item) {
			$('.comment-container').append(createThread(item).object);
		});
		var hasprev = 'prev' in res.pager, hasnext = 'next' in res.pager;
		var prev = $('#pager-prev');
		var next = $('#pager-next');
		var first = $('#pager-first');
		var last = $('#pager-last');
		if (query.dir === 'prev') {
			// Sigh.. when can MW allow ES6?!!
			var tmp = next;
			next = prev;
			prev = tmp;
			tmp = first;
			first = last;
			last = tmp;
		}
		if ('offset' in query) delete query.offset;
		prev.attr('href', mw.util.getUrl(null,
			(hasprev ? $.extend({}, query, { from: res.pager.previd }) : query)
		)).toggleClass('pager-disable', !hasprev);
		next.attr('href', mw.util.getUrl(null,
			(hasnext ? $.extend({}, query, { from: res.pager.nextid }) : query)
		)).toggleClass('pager-disable', !hasnext);
		first.toggleClass('pager-disable', !hasprev);
		last.toggleClass('pager-disable', !hasnext);
	});
}

$('#pager-prev,#pager-next,#pager-first,#pager-last').on('click', function(event) {
	// Pagers are "hi-jacked" by JS and does not actually cause page fresh
	event.preventDefault();
	if (this.className === 'pager-disable') return;
	// Still change the URL
	history.pushState({}, '', this.href);
	// Trigger a comment reload, so it looks like the page has been updated
	loadComments();
});

$(window).on('popstate', function() {
	loadComments();
});

$('#bodyContent').after('<div class="comment-container"></div>');
loadComments();

function wrapButtonMsg(msg) {
	return '<button>' + mw.msg(msg) + '</button>'
}

// Setup batch actions
var selectAll = $(wrapButtonMsg('flowthread-ui-selectall'));
$("#mw-content-text").append(selectAll);
selectAll.click(function() {
	$('.comment-thread').addClass('comment-selected');
	onSelect();
});

var unselectAll = $(wrapButtonMsg('flowthread-ui-unselectall'));
$("#mw-content-text").append(unselectAll);
unselectAll.click(function() {
	$('.comment-thread').removeClass('comment-selected');
	onSelect();
});

function getSelectedThreads() {
	return Array.prototype.map.call($('.comment-selected'), function(obj) {
		return $.data(obj, 'thread');
	})
}

if (deleted) {
	var batchRecover = $(wrapButtonMsg('flowthread-ui-recover'));
	$("#mw-content-text").append(batchRecover);
	batchRecover.click(function() {
		Thread.recover(getSelectedThreads());
	});

	if (fulladmin) {
		var batchErase = $(wrapButtonMsg('flowthread-ui-erase'));
		$("#mw-content-text").append(batchErase);
		batchErase.click(function() {
			Thread.erase(getSelectedThreads());
		});
	}
} else {
	var batchDelete = $(wrapButtonMsg('flowthread-ui-delete'));
	$("#mw-content-text").append(batchDelete);
	batchDelete.click(function() {
		Thread.delete(getSelectedThreads());
	});

	if (fulladmin) {
		var batchMarkchecked = $(wrapButtonMsg('flowthread-ui-markchecked'));
		$("#mw-content-text").append(batchMarkchecked);
		batchMarkchecked.click(function() {
			Thread.markchecked(getSelectedThreads());
		});
	}
}

function onSelect() {
	if ($('.comment-selected').length) {
		if (batchRecover) batchRecover.show();
		if (batchErase) batchErase.show();
		if (batchDelete) batchDelete.show();
		if (batchMarkchecked) batchMarkchecked.show();
		selectAll.hide();
		unselectAll.show();
	} else {
		if (batchRecover) batchRecover.hide();
		if (batchErase) batchErase.hide();
		if (batchDelete) batchDelete.hide();
		if (batchMarkchecked) batchMarkchecked.hide();
		selectAll.show();
		unselectAll.hide();
	}
}
onSelect(); // Hide batch actions
