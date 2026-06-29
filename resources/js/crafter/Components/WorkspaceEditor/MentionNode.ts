import Mention from '@tiptap/extension-mention';
import { VueRenderer } from '@tiptap/vue-3';
import tippy, { type Instance as TippyInstance } from 'tippy.js';
import 'tippy.js/dist/tippy.css';
import axios from 'axios';
import MentionList from './MentionList.vue';

export const buildMentionExtension = () => {
    return Mention.configure({
        HTMLAttributes: {
            class: 'argo-mention px-1 rounded bg-primary-100 text-primary-700 font-medium',
            'data-mention': '',
        },
        renderHTML({ options, node }) {
            return [
                'span',
                {
                    ...options.HTMLAttributes,
                    'data-user-id': node.attrs.id,
                },
                `@${node.attrs.label ?? node.attrs.id}`,
            ];
        },
        suggestion: {
            items: async ({ query }) => {
                try {
                    const resp = await axios.get(route('crafter.argo-task.mentions.search'), {
                        params: { q: query },
                    });
                    return resp.data.users ?? [];
                } catch {
                    return [];
                }
            },
            render: () => {
                let component: VueRenderer;
                let popup: TippyInstance[] = [];

                return {
                    onStart: (props) => {
                        component = new VueRenderer(MentionList, {
                            props,
                            editor: props.editor,
                        });
                        if (!props.clientRect) return;
                        popup = tippy('body', {
                            getReferenceClientRect: props.clientRect as any,
                            appendTo: () => document.body,
                            content: component.element,
                            showOnCreate: true,
                            interactive: true,
                            trigger: 'manual',
                            placement: 'bottom-start',
                        });
                    },
                    onUpdate: (props) => {
                        component?.updateProps(props);
                        if (!props.clientRect) return;
                        popup[0]?.setProps({ getReferenceClientRect: props.clientRect as any });
                    },
                    onKeyDown: (props) => {
                        if (props.event.key === 'Escape') {
                            popup[0]?.hide();
                            return true;
                        }
                        return (component?.ref as any)?.onKeyDown?.(props.event) ?? false;
                    },
                    onExit: () => {
                        popup[0]?.destroy();
                        component?.destroy();
                    },
                };
            },
        },
    });
};
