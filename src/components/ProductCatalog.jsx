import React, { useState, useMemo } from 'react';
import './ProductCatalog.css';

const ProductCatalog = ({ products = [], categories = [], onProductClick }) => {
  const [selectedCategory, setSelectedCategory] = useState('');
  const [searchQuery, setSearchQuery] = useState('');
  const [sortOption, setSortOption] = useState('name');
  const [priceRange, setPriceRange] = useState({ min: 0, max: 1000 });

  // Filter and sort products
  const filteredAndSortedProducts = useMemo(() => {
    let filtered = products.filter(product => {
      // Category filter
      if (selectedCategory && product.category !== selectedCategory) {
        return false;
      }
      
      // Search filter
      if (searchQuery && 
          !product.name.toLowerCase().includes(searchQuery.toLowerCase()) &&
          !product.description.toLowerCase().includes(searchQuery.toLowerCase())) {
        return false;
      }
      
      // Price filter
      if (product.price < priceRange.min || product.price > priceRange.max) {
        return false;
      }
      
      return true;
    });

    // Sort products
    filtered.sort((a, b) => {
      switch (sortOption) {
        case 'name':
          return a.name.localeCompare(b.name);
        case 'price-low':
          return a.price - b.price;
        case 'price-high':
          return b.price - a.price;
        case 'rating':
          return b.rating - a.rating;
        default:
          return 0;
      }
    });

    return filtered;
  }, [products, selectedCategory, searchQuery, sortOption, priceRange]);

  // Get unique categories from products
  const uniqueCategories = useMemo(() => {
    const categoriesSet = new Set(products.map(p => p.category));
    return Array.from(categoriesSet).sort();
  }, [products]);

  const handleResetFilters = () => {
    setSelectedCategory('');
    setSearchQuery('');
    setPriceRange({ min: 0, max: 1000 });
  };

  const handlePriceChange = (min, max) => {
    setPriceRange({ min, max });
  };

  return (
    <div className="product-catalog">
      <div className="catalog-header">
        <h2>Product Catalog</h2>
        <p>{filteredAndSortedProducts.length} products found</p>
      </div>

      {/* Filters */}
      <div className="filters">
        <div className="filter-group">
          <label htmlFor="category">Category</label>
          <select 
            id="category"
            value={selectedCategory} 
            onChange={(e) => setSelectedCategory(e.target.value)}
          >
            <option value="">All Categories</option>
            {uniqueCategories.map(category => (
              <option key={category} value={category}>{category}</option>
            ))}
          </select>
        </div>

        <div className="filter-group">
          <label htmlFor="search">Search</label>
          <input
            id="search"
            type="text"
            placeholder="Search products..."
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
          />
        </div>

        <div className="filter-group">
          <label htmlFor="price">Price Range</label>
          <div className="price-range">
            <span>${priceRange.min}</span>
            <input 
              type="range" 
              min="0" 
              max="1000" 
              value={priceRange.max}
              onChange={(e) => handlePriceChange(priceRange.min, parseInt(e.target.value))}
            />
            <span>${priceRange.max}</span>
          </div>
        </div>

        <div className="filter-group">
          <label htmlFor="sort">Sort By</label>
          <select 
            id="sort"
            value={sortOption} 
            onChange={(e) => setSortOption(e.target.value)}
          >
            <option value="name">Name</option>
            <option value="price-low">Price: Low to High</option>
            <option value="price-high">Price: High to Low</option>
            <option value="rating">Rating</option>
          </select>
        </div>

        <button className="reset-filters" onClick={handleResetFilters}>
          Reset Filters
        </button>
      </div>

      {/* Products Grid */}
      <div className="products-grid">
        {filteredAndSortedProducts.length === 0 ? (
          <p className="no-products">No products match your filters</p>
        ) : (
          filteredAndSortedProducts.map(product => (
            <div 
              key={product.id} 
              className="product-card"
              onClick={() => onProductClick && onProductClick(product)}
            >
              <div className="product-image">
                <img src={product.image} alt={product.name} />
              </div>
              <div className="product-info">
                <h3 className="product-name">{product.name}</h3>
                <p className="product-description">{product.description}</p>
                <div className="product-meta">
                  <span className="product-price">${product.price.toFixed(2)}</span>
                  <span className="product-rating">
                    {product.rating} ⭐
                  </span>
                </div>
                <div className="product-category">
                  {product.category}
                </div>
              </div>
            </div>
          ))
        )}
      </div>
    </div>
  );
};

export default ProductCatalog;