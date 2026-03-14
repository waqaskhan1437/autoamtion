<?php
/**
 * HTMLComponentHelper Class
 * Centralized HTML components for the Video Workflow Manager
 * 
 * Consolidates reusable HTML patterns like cards and buttons to reduce duplication
 * and maintain consistent styling across the application.
 * 
 * @author Kilo
 * @version 1.0
 */

class HTMLComponentHelper {
    /**
     * Create a standard card component
     * 
     * @param string $content Card content
     * @param string $classes Additional CSS classes
     * @param string $title Card title (optional)
     * @param string $icon SVG icon (optional)
     * @return string Complete card HTML
     */
    public static function createCard(string $content, string $classes = '', string $title = '', string $icon = ''): string {
        $cardClasses = 'card rounded-lg p-4 mb-6 ' . $classes;
        
        $html = '<div class="' . $cardClasses . '">';
        
        if ($title || $icon) {
            $html .= '<div class="flex items-center justify-between border-b border-gray-800 pb-4 mb-4">';
            if ($icon) {
                $html .= '<div class="w-10 h-10 rounded-lg flex items-center justify-center bg-gray-700">' . $icon . '</div>';
            }
            if ($title) {
                $html .= '<h3 class="font-semibold text-xl">' . htmlspecialchars($title) . '</h3>';
            }
            $html .= '</div>';
        }
        
        $html .= $content;
        $html .= '</div>';
        
        return $html;
    }

    /**
     * Create a button with consistent styling
     * 
     * @param string $text Button text
     * @param string $classes Additional CSS classes
     * @param string $icon SVG icon (optional)
     * @param string $type Button type (default: button)
     * @param string $onClick JavaScript onclick handler (optional)
     * @return string Complete button HTML
     */
    public static function createButton(string $text, string $classes = '', string $icon = '', string $type = 'button', string $onClick = ''): string {
        $buttonClasses = 'px-4 py-2 rounded-lg font-medium hover:opacity-90 transition-opacity ' . $classes;
        
        $html = '<button type="' . htmlspecialchars($type) . '" class="' . $buttonClasses . '" ';
        
        if ($onClick) {
            $html .= 'onclick="' . htmlspecialchars($onClick) . '" ';
        }
        
        $html .= '>';
        
        if ($icon) {
            $html .= $icon . '<span class="ml-2">' . htmlspecialchars($text) . '</span>';
        } else {
            $html .= htmlspecialchars($text);
        }
        
        $html .= '</button>';
        
        return $html;
    }

    /**
     * Create a status badge
     * 
     * @param string $status Status text
     * @param string $type Status type (success, error, warning, info)
     * @return string Complete badge HTML
     */
    public static function createStatusBadge(string $status, string $type = 'info'): string {
        $colorClasses = [
            'success' => 'bg-green-500/10 text-green-500',
            'error' => 'bg-red-500/10 text-red-500',
            'warning' => 'bg-yellow-500/10 text-yellow-500',
            'info' => 'bg-blue-500/10 text-blue-500',
            'processing' => 'bg-blue-500/10 text-blue-500 animate-pulse',
            'default' => 'bg-gray-500/10 text-gray-400',
        ];
        
        $classes = $colorClasses[$type] ?? $colorClasses['default'];
        
        return '<span class="px-2 py-1 rounded text-xs font-medium ' . $classes . '">' . htmlspecialchars($status) . '</span>';
    }

    /**
     * Create an action button with platform icon
     * 
     * @param string $action Action name
     * @param string $platform Platform name
     * @param string $status Status (success, error, etc.)
     * @return string Complete action button HTML
     */
    public static function createActionPlatformButton(string $action, string $platform, string $status = 'info'): string {
        $platformIcons = [
            'youtube' => '<svg class="w-4 h-4 text-red-500" fill="currentColor" viewBox="0 0 24 24"><path d="M19.615 3.184c-3.604-.246-11.631-.245-15.23 0-3.897.266-4.356 2.62-4.385 8.816.029 6.185.484 8.549 4.385 8.816 3.6.245 11.626.246 15.23 0 3.897-.266 4.356-2.62 4.385-8.816-.029-6.185-.484-8.549-4.385-8.816zm-10.615 12.816v-8l8 3.993-8 4.007z"/></svg>',
            'tiktok' => '<svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M19.59 6.69a4.83 4.83 0 01-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 01-5.2 1.74 2.89 2.89 0 012.31-4.64 2.93 2.93 0 01.88.13V9.4a6.84 6.84 0 00-1-.05A6.33 6.33 0 005 20.1a6.34 6.34 0 0010.86-4.43v-7a8.16 8.16 0 004.77 1.52v-3.4a4.85 4.85 0 01-1-.1z"/></svg>',
            'instagram' => '<svg class="w-4 h-4 text-pink-500" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069z"/></svg>',
            'facebook' => '<svg class="w-4 h-4 text-blue-500" fill="currentColor" viewBox="0 0 24 24"><path d="M9 8h-3v4h3v12h5v-12h3.642l.358-4h-4v-1.667c0-.955.192-1.333 1.115-1.333h2.885v-5h-3.808c-3.596 0-5.192 1.583-5.192 4.615v3.385z"/></svg>',
            'threads' => '<svg class="w-4 h-4 text-gray-400" fill="currentColor" viewBox="0 0 24 24"><path d="M12.186 24h-.007c-3.581-.024-6.334-1.205-8.184-3.509C2.35 18.44 1.5 15.586 1.5 12.186V12c.018-3.724 1.084-6.567 3.168-8.454C6.553 1.706 9.263 1 12 1c2.732 0 5.428.702 7.317 2.548 2.085 1.892 3.152 4.733 3.183 8.452v.186c-.017 3.712-1.079 6.554-3.157 8.446C17.459 22.5 14.778 23.2 12.186 24z"/></svg>',
            'postforme' => '<svg class="w-4 h-4 text-pink-400" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg>',
        ];
        
        $icon = $platformIcons[$platform] ?? '';
        
        return '<div class="flex items-center gap-2 text-sm text-gray-400">' . $icon . '<span>' . htmlspecialchars($action) . '</span></div>';
    }

    /**
     * Create a card header
     * 
     * @param string $title Header title
     * @param string $classes Additional CSS classes
     * @return string Complete header HTML
     */
    public static function createCardHeader(string $title, string $classes = ''): string {
        $headerClasses = 'flex items-center justify-between border-b border-gray-800 pb-4 mb-4 ' . $classes;
        
        return '<div class="' . $headerClasses . '"><h3 class="font-semibold text-xl">' . htmlspecialchars($title) . '</h3></div>';
    }

    /**
     * Create a card body
     * 
     * @param string $content Card content
     * @param string $classes Additional CSS classes
     * @return string Complete body HTML
     */
    public static function createCardBody(string $content, string $classes = ''): string {
        $bodyClasses = 'p-4 ' . $classes;
        
        return '<div class="' . $bodyClasses . '">' . $content . '</div>';
    }

    /**
     * Create a card footer
     * 
     * @param string $content Footer content
     * @param string $classes Additional CSS classes
     * @return string Complete footer HTML
     */
    public static function createCardFooter(string $content, string $classes = ''): string {
        $footerClasses = 'p-4 border-t border-gray-800 mt-4 ' . $classes;
        
        return '<div class="' . $footerClasses . '">' . $content . '</div>';
    }

    /**
     * Create a grid container
     * 
     * @param string $content Grid content
     * @param string $classes Additional CSS classes
     * @return string Complete grid HTML
     */
    public static function createGrid(string $content, string $classes = ''): string {
        $gridClasses = 'grid gap-4 ' . $classes;
        
        return '<div class="' . $gridClasses . '">' . $content . '</div>';
    }

    /**
     * Create a form with consistent styling
     * 
     * @param string $content Form content
     * @param string $classes Additional CSS classes
     * @return string Complete form HTML
     */
    public static function createForm(string $content, string $classes = ''): string {
        $formClasses = 'space-y-4 ' . $classes;
        
        return '<form class="' . $formClasses . '">' . $content . '</form>';
    }

    /**
     * Create a form field group
     * 
     * @param string $label Field label
     * @param string $input HTML input element
     * @param string $helpText Help text (optional)
     * @return string Complete field group HTML
     */
    public static function createFormField(string $label, string $input, string $helpText = ''): string {
        $html = '<div class="flex flex-col">';
        $html .= '<label class="text-sm font-medium text-gray-400 mb-1">' . htmlspecialchars($label) . '</label>';
        $html .= $input;
        
        if ($helpText) {
            $html .= '<div class="text-xs text-gray-500 mt-1">' . htmlspecialchars($helpText) . '</div>';
        }
        
        $html .= '</div>';
        
        return $html;
    }
}