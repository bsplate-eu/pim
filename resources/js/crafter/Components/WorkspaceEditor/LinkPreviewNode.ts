import { Node, mergeAttributes } from '@tiptap/core';

export interface LinkPreviewAttrs {
    url: string;
    title?: string | null;
    description?: string | null;
    image?: string | null;
    host?: string | null;
}

export const LinkPreviewNode = Node.create({
    name: 'linkPreview',
    group: 'block',
    atom: true,
    draggable: true,
    selectable: true,

    addAttributes() {
        return {
            url:         { default: '' },
            title:       { default: null },
            description: { default: null },
            image:       { default: null },
            host:        { default: null },
        };
    },

    parseHTML() {
        return [{ tag: 'div[data-link-preview]' }];
    },

    renderHTML({ HTMLAttributes }) {
        return [
            'div',
            mergeAttributes(HTMLAttributes, {
                'data-link-preview': '',
                'data-url': HTMLAttributes.url,
                class: 'argo-link-preview my-3 rounded-lg border border-gray-200 overflow-hidden hover:border-gray-300 bg-white',
            }),
            [
                'a',
                { href: HTMLAttributes.url, target: '_blank', rel: 'noopener noreferrer', class: 'flex no-underline text-inherit' },
                HTMLAttributes.image
                    ? ['img', { src: HTMLAttributes.image, class: 'w-32 h-24 object-cover flex-shrink-0', alt: '' }]
                    : ['span', { class: 'w-32 h-24 flex items-center justify-center bg-gray-100 text-gray-400 flex-shrink-0' }, '🔗'],
                [
                    'div',
                    { class: 'flex-1 p-3 min-w-0' },
                    ['div', { class: 'text-sm font-semibold text-gray-900 truncate' }, HTMLAttributes.title || HTMLAttributes.url],
                    HTMLAttributes.description
                        ? ['div', { class: 'text-xs text-gray-500 mt-1 line-clamp-2' }, HTMLAttributes.description]
                        : ['div', {}, ''],
                    ['div', { class: 'text-xs text-gray-400 mt-2' }, HTMLAttributes.host || ''],
                ],
            ],
        ];
    },

    addCommands() {
        return {
            setLinkPreview: (attrs: LinkPreviewAttrs) => ({ commands }) => {
                return commands.insertContent([
                    { type: this.name, attrs },
                    { type: 'paragraph' },
                ]);
            },
        } as any;
    },
});
