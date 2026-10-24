import Icon from './Icon';

const suggestionOrder = [
    ['answers', 'message'],
    ['ideas', 'bulb'],
    ['summarize', 'document'],
    ['write', 'pen'],
];

const starterOrder = ['explain', 'shorter', 'options', 'rewrite'];

export function Home({ copy, onInsert }) {
    const suggestions = copy.suggestions ?? {};

    return (
        <div className="home-inner">
            <div className="hero">
                <h1 className="display hero-name">{copy.app_name}</h1>
                <p className="display hero-greeting">{copy.greeting}</p>
                <p className="hero-sub">{copy.empty}</p>
            </div>
            <div className="suggestions">
                {suggestionOrder.map(([id, icon]) => {
                    const item = suggestions[id];

                    if (!item) {
                        return null;
                    }

                    return (
                        <button key={id} type="button" className="suggestion" onClick={() => onInsert(item.prompt)}>
                            <span className="suggestion-icon">
                                <Icon name={icon} />
                            </span>
                            <span className="suggestion-title">{item.title}</span>
                            <span className="suggestion-body">{item.body}</span>
                        </button>
                    );
                })}
            </div>
        </div>
    );
}

export function Starters({ copy, onInsert }) {
    const starters = copy.starters ?? {};

    return (
        <div className="starters">
            {starterOrder.map((id) => {
                const item = starters[id];

                if (!item) {
                    return null;
                }

                return (
                    <button key={id} type="button" className="starter" onClick={() => onInsert(item.prompt)}>
                        <span>{item.label}</span>
                        <Icon name="chevron" className="starter-arrow" />
                    </button>
                );
            })}
        </div>
    );
}
