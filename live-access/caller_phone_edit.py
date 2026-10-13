"""Scoped compiled-template support for native saved-call contact editing."""
from copy import deepcopy
import json
import re


def patch_saved_call(text, scripts, form_block):
    def module(name, transform):
        nonlocal text
        marker = 'define(' + json.dumps(name) + ','
        if text.count(marker) != 1:
            raise ValueError('Expected one native module: ' + name)
        start = text.index(marker)
        end = text.find('\ndefine(', start + len(marker))
        if end < 0:
            end = len(text)
        text = text[:start] + transform(text[start:end]) + text[end:]

    def template(section, edit):
        match = re.search(r'"block":\s*("(?:\\.|[^"\\])*")', section)
        if not match:
            raise ValueError('Missing saved-call template block.')
        block = json.loads(json.loads(match.group(1)))
        edit(block)
        encoded = json.dumps(json.dumps(block, separators=(',', ':')), ensure_ascii=False)
        return section[:match.start(1)] + encoded + section[match.end(1):]

    def modal_patch(section):
        def edit(block):
            name = 'order/caller-phone'
            if name in block[3]:
                return
            block[3].append(name)
            resource = [30, block[1].index('@options') + 1, ['order']]
            field = [8, [39, len(block[3]) - 1], None, [['@resource'], [resource]], None]
            statements = block[0][0][4][1][0][0]
            input_id = block[3].index('input-group')
            value = [30, block[1].index('@options') + 1, ['order', 'internal_id']]
            at = [i for i, node in enumerate(statements) if isinstance(node, list) and len(node) > 3
                  and node[:2] == [8, [39, input_id]] and node[3] and value in node[3][1]]
            if len(at) != 1:
                raise ValueError('Missing saved-call modal field anchor.')
            statements.insert(at[0], field)
        section = template(section, edit)
        if '<Order::CallerPhone ' not in section:
            anchor = '<InputGroup @name={{t "common.internal-id"}}'
            if section.count(anchor) != 1:
                raise ValueError('Missing modal source-comment anchor.')
            section = section.replace(anchor, '<Order::CallerPhone @resource={{@options.order}} />\n              ' + anchor, 1)
        return section

    module('@fleetbase/fleetops-engine/components/modals/order-form', modal_patch)
    methods = (scripts / 'mapa-caller-phone-methods.js.txt').read_text(encoding='utf-8').replace('@action ', '')
    getter = methods[:methods.index('    mapaNormalizeCallerPhone(')]

    def display_patch(section):
        def edit(block):
            marker = 'field-info-container field-vertical-container dashed-bottom mapa-caller-phone-display'
            statements = block[0][0][4][1][block[0][0][4][0].index('default')][0]
            if [14, 0, marker] in statements:
                return
            resource = block[1].index('@resource') + 1
            value = [52, [30, resource, ['meta', '_index_resource']], 'Loading caller details...',
                     [52, [30, 0, ['mapaCallerPhone']], [30, 0, ['mapaCallerPhone']], 'Not provided']]
            field = [[10, 0], [14, 0, marker], [12], [10, 0], [14, 0, 'field-name'], [12],
                     [1, 'Caller phone number'], [13], [10, 0], [14, 0, 'field-value'], [12], [1, value], [13], [13]]
            at = statements.index([14, 0, 'field-info-container field-vertical-container dashed-bottom']) - 1
            if statements[at] != [10, 0]:
                raise ValueError('Unexpected read-only details field boundary.')
            statements[at:at] = field
        section = template(section, edit)
        if '<div class="field-info-container field-vertical-container dashed-bottom mapa-caller-phone-display">' not in section:
            anchor = '<div class="field-info-container field-vertical-container dashed-bottom">'
            section = section.replace(anchor, (scripts / 'mapa-caller-phone-display.hbs').read_text(encoding='utf-8') + anchor, 1)
        if 'get mapaCallerPhone()' not in section:
            anchor = '    get actionButtons() {'
            if section.count(anchor) != 1:
                raise ValueError('Missing read-only Details getter anchor.')
            section = section.replace(anchor, getter + anchor, 1)
        return section

    module('@fleetbase/fleetops-engine/components/order/details/detail', display_patch)

    def actions_patch(section):
        name = '    editOrderDetails(order, options = {}) {'
        async_name = '    async editOrderDetails(order, options = {}) {'
        start = section.find(async_name if async_name in section else name)
        end = section.find('    async assignDriver(order)', start)
        if start < 0 or end < 0:
            raise ValueError('Missing native edit-order action boundaries.')
        action = section[start:end]
        if 'const mapaCallerContactBeforeEdit' not in action:
            action = action.replace(name, async_name, 1)
            anchor = '      options = options === null ? {} : options;'
            if action.count(anchor) != 1:
                raise ValueError('Missing native edit options anchor.')
            action = action.replace(anchor, anchor + '''
      if (order.meta?._index_resource) {
        try { await order.reload(); }
        catch (error) { this.notifications.serverError(error); return; }
        if (order.meta?._index_resource) {
          this.notifications.warning('Call details are still loading. Please reopen Edit after loading finishes.');
          return;
        }
      }
      const mapaCallerContactBeforeEdit = {
        present: Object.prototype.hasOwnProperty.call(order.meta ?? {}, 'mapa_caller_phone'),
        value: order.meta?.mapa_caller_phone
      };''', 1)
            cancel = '          order.payload.rollbackAttributes();'
            if action.count(cancel) != 1:
                raise ValueError('Missing native modal cancel anchor.')
            action = action.replace(cancel, '''          const contactMeta = { ...(order.meta ?? {}) };
          if (mapaCallerContactBeforeEdit.present) contactMeta.mapa_caller_phone = mapaCallerContactBeforeEdit.value;
          else delete contactMeta.mapa_caller_phone;
          order.set('meta', contactMeta);
''' + cancel, 1)
        return section[:start] + action + section[end:]

    module('@fleetbase/fleetops-engine/services/order-actions', actions_patch)

    # Reuse the exact tested native input block with only its two local symbols remapped.
    statements = form_block[0][0][4][1][0][0]
    fields = [node for node in statements if isinstance(node, list) and len(node) > 3
              and node[0] == 8 and node[3] and 'Caller phone number' in node[3][1]]
    if len(fields) != 1:
        raise ValueError('Expected one original caller phone field.')
    resource = form_block[1].index('@resource') + 1
    field_local = form_block[1].index('mapaCallerPhoneId') + 1
    def remap(node):
        if not isinstance(node, list):
            return node
        if node == [30, resource]:
            return [30, 1]
        if len(node) > 2 and node[:2] == [30, resource]:
            return [30, 1, *deepcopy(node[2:])]
        if node == [30, field_local]:
            return [30, 2]
        return [remap(child) for child in node]
    field = remap(deepcopy(fields[0]))
    field[3][1][field[3][0].index('@wrapperClass')] = 'col-span-2'
    field[4][1][0][1] = [2]
    block = [[field], ['@resource', 'callerPhoneId'], False, deepcopy(form_block[3])]
    name = '@fleetbase/fleetops-engine/components/order/caller-phone'
    factory = json.dumps({'id': 'mapa-caller-contact', 'block': json.dumps(block, separators=(',', ':')),
                          'moduleName': name + '.hbs', 'isStrictMode': False}, separators=(',', ':'))
    new_module = '''define(''' + json.dumps(name) + ''', ["exports", "@ember/component", "@glimmer/component", "@ember/template-factory"], function (_exports, _component, _glimmer, _templateFactory) {
  "use strict";
  Object.defineProperty(_exports, "__esModule", {value:true});
  const template = (0, _templateFactory.createTemplateFactory)(''' + factory + ''');
  class MapaCallerPhone extends _glimmer.default {
    constructor(...args) {
      super(...args);
      this.setMapaCallerPhone = this.setMapaCallerPhone.bind(this);
    }
''' + methods + '''  }
  _exports.default = (0, _component.setComponentTemplate)(template, MapaCallerPhone);
});
'''
    if 'define(' + json.dumps(name) + ',' in text:
        module(name, lambda section: new_module)
    else:
        text = text.rstrip() + '\n' + new_module
    return text
