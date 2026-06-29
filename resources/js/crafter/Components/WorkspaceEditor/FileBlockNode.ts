import { Node, mergeAttributes } from '@tiptap/core';

export interface FileBlockAttrs {
    url: string;
    name: string;
    size?: number | null;
    mime?: string | null;
}

export const FileBlockNode = Node.create({
    name: 'fileBlock',
    group: 'block',
    atom: true,
    draggable: true,
    selectable: true,

    addAttributes() {
        return {
            url:  { default: '' },
            name: { default: '' },
            size: { default: null },
            mime: { default: null },
        };
    },

    parseHTML() {
        return [{ tag: 'div[data-file-block]' }];
    },

    renderHTML({ HTMLAttributes }) {
        const sizeStr = HTMLAttributes.size
            ? ` · ${Math.round((Number(HTMLAttributes.size) / 1024) * 10) / 10} KB`
            : '';
        return [
            'div',
            mergeAttributes(HTMLAttributes, {
                'data-file-block': '',
                class: 'argo-file-block flex items-center gap-3 p-3 my-2 rounded-md border border-gray-200 bg-gray-50 hover:bg-gray-100',
            }),
            [
                'a',
                { href: HTMLAttributes.url, target: '_blank', rel: 'noopener noreferrer', class: 'flex items-center gap-3 no-underline text-gray-800 flex-1' },
                ['span', { class: 'inline-flex items-center justify-center w-8 h-8 rounded bg-white border border-gray-200 text-gray-500 text-xs font-bold' }, '📎'],
                ['span', { class: 'flex-1 text-sm' }, `${HTMLAttributes.name}${sizeStr}`],
            ],
        ];
    },

    addCommands() {
        return {
            setFileBlock: (attrs: FileBlockAttrs) => ({ commands }) => {
                return commands.insertContent([
                    { type: this.name, attrs },
                    { type: 'paragraph' },
                ]);
            },
        } as any;
    },
});
